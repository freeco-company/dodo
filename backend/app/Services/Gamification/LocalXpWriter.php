<?php

namespace App\Services\Gamification;

use App\Models\User;
use App\Services\GameXp;

/**
 * Single sink for "earn local XP and bump level on User row".
 *
 * ADR-009 Phase B.3 cutover — every local +XP path (cards / quest / journey /
 * checkin / pet interact) used to write users.{xp,level} directly AND publish
 * to py-service. With the cutover, py-service ledger is the source of truth
 * and the local mirror is updated by GroupProgressionMirror on inbound webhook.
 *
 * Flag-gated to make the cutover reversible:
 *
 *   GAMIFICATION_LOCAL_XP_WRITES_ENABLED=true   (default, current behaviour)
 *     → every site continues to write local users.{xp,level}; webhook later
 *       arrives with the same numbers and is a no-op (mirror only forward-
 *       moves, see GroupProgressionMirror.applyLevelUp).
 *
 *   GAMIFICATION_LOCAL_XP_WRITES_ENABLED=false  (post-cutover)
 *     → call sites still publish to py-service for ledger accumulation, but
 *       this writer becomes a no-op. Frontend MUST already have optimistic-UI
 *       in place or the user sees a one-tick lag.
 *
 * Returns the level after the write so callers can detect a level-up boundary.
 * In disabled mode returns the current level unchanged so existing
 * level-crossed branching (achievement-on-level-up etc) doesn't fire spuriously.
 */
class LocalXpWriter
{
    /**
     * Apply a positive XP delta to the user's local mirror row. Returns the
     * `[$levelBefore, $levelAfter, $applied]` tuple so callers can decide what
     * downstream events / responses to fire.
     *
     * Phase B.3 cutover note (frontend optimistic UI):
     *   When the flag is OFF, the local row is NOT updated, but `levelAfter`
     *   still reflects what the level WILL be after the webhook arrives. This
     *   lets API responses keep returning correct celebration data
     *   (`leveled_up`, `level_after`) so the frontend can show +XP / level-up
     *   immediately. The user briefly sees the optimistic value; the next
     *   bootstrap (after webhook lands ~3s later) reads the canonical mirror.
     *
     * @return array{0:int, 1:int, 2:bool}  [levelBefore, levelAfter, didWrite]
     */
    public function apply(User $user, int $xpDelta): array
    {
        $levelBefore = (int) ($user->level ?? 1);
        if ($xpDelta <= 0) {
            return [$levelBefore, $levelBefore, false];
        }

        // Forecast — the level once the publisher round-trip lands. Used both
        // for the immediate API response (optimistic UI) and as the actual
        // write target when the flag is on.
        $forecastXp = (int) ($user->xp ?? 0) + $xpDelta;
        $forecastLevel = GameXp::levelForXp($forecastXp);

        if (! $this->enabled()) {
            // Optimistic mode: don't write, but still forecast the new level
            // so the response carries `leveled_up = (after > before)` correctly.
            return [$levelBefore, $forecastLevel, false];
        }

        $user->xp = $forecastXp;
        $user->level = $forecastLevel;
        $user->save();

        return [$levelBefore, $forecastLevel, true];
    }

    public function enabled(): bool
    {
        return (bool) config('services.pandora_gamification.local_xp_writes_enabled', true);
    }
}
