<?php

namespace App\Services\Gamification;

use App\Models\User;

/**
 * Apply a `gamification.level_up` webhook to the local `users` mirror.
 *
 * ADR-009 §2.4 transition note: until Phase B.3 fully cuts the local game
 * logic over to py-service ledger as the source of truth, we treat this
 * webhook as authoritative — it bumps `users.level` / `users.xp` /
 * `group_level_name_*` to whatever py-service says, even if the local
 * GameXp logic disagrees. Per ADR, the ledger wins.
 *
 * The handler is intentionally **lossy on stale events** (older payloads
 * arriving after newer ones can't decrease a user's level). This is the
 * simplest correct behaviour while two systems coexist; once Phase B.3
 * lands and local game logic stops writing `users.level`, the check goes
 * away.
 */
class GroupProgressionMirror
{
    /**
     * @param  array<string, mixed>  $payload  Webhook payload — typed loosely
     *                                         because this is a wire-format
     *                                         boundary (consumer of py-service).
     */
    public function applyLevelUp(string $pandoraUserUuid, array $payload): bool
    {
        if ($pandoraUserUuid === '') {
            return false;
        }
        $user = User::where('pandora_user_uuid', $pandoraUserUuid)->first();
        if ($user === null) {
            // No mirror row yet — silently drop. The webhook will arrive again
            // on next level-up (if ever); for the very first time ADR-009
            // Phase B's user-creation flow will seed a baseline.
            return false;
        }

        $newLevel = (int) ($payload['new_level'] ?? 0);
        if ($newLevel <= 0) {
            return false;
        }

        $changed = false;
        if ($newLevel > (int) $user->level) {
            $user->level = $newLevel;
            $changed = true;
        }
        if (isset($payload['total_xp'])) {
            $totalXp = (int) $payload['total_xp'];
            if ($totalXp > (int) $user->xp) {
                $user->xp = $totalXp;
                $changed = true;
            }
        }

        if ($changed) {
            $user->save();
        }

        return $changed;
    }
}
