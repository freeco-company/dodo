<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Translated from ai-game/src/services/account_deletion.ts.
 *
 * Apple App Store Guideline 5.1.1(v) requires in-app deletion. Also satisfies
 * 個資法 right-to-erasure.
 *
 * Two-phase to avoid accidental loss:
 *   1. request()   → soft flag + 7-day cooldown
 *   2. purge()     → hard DELETE after cooldown elapses (cron)
 */
class AccountDeletionService
{
    public const COOLDOWN_DAYS = 7;

    /** @return array{hard_delete_after:string} */
    public function request(User $user): array
    {
        $now = Carbon::now();
        $hardDeleteAfter = $now->copy()->addDays(self::COOLDOWN_DAYS);
        $user->deletion_requested_at = $now;
        $user->hard_delete_after = $hardDeleteAfter;
        $user->save();
        return ['hard_delete_after' => $hardDeleteAfter->toIso8601String()];
    }

    public function restore(User $user): bool
    {
        if (! $user->deletion_requested_at) return false;
        if ($user->hard_delete_after && $user->hard_delete_after->isPast()) return false;
        $user->deletion_requested_at = null;
        $user->hard_delete_after = null;
        $user->save();
        return true;
    }

    /** Cron: hard-delete users whose cooldown has elapsed. Cascade FKs handle the rest. */
    public function purge(): int
    {
        return User::whereNotNull('hard_delete_after')
            ->where('hard_delete_after', '<', Carbon::now())
            ->delete();
    }
}
