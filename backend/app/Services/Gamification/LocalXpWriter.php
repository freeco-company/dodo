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
     * @return array{0:int, 1:int, 2:bool}  [levelBefore, levelAfter, didWrite]
     */
    public function apply(User $user, int $xpDelta): array
    {
        $levelBefore = (int) ($user->level ?? 1);
        if ($xpDelta <= 0) {
            return [$levelBefore, $levelBefore, false];
        }

        if (! $this->enabled()) {
            return [$levelBefore, $levelBefore, false];
        }

        $user->xp = (int) ($user->xp ?? 0) + $xpDelta;
        $user->level = GameXp::levelForXp((int) $user->xp);
        $user->save();

        return [$levelBefore, (int) $user->level, true];
    }

    public function enabled(): bool
    {
        return (bool) config('services.pandora_gamification.local_xp_writes_enabled', true);
    }
}
