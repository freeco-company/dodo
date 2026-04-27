<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Translated from ai-game/src/services/shield.ts.
 * Streak shield protects 1 missed day, refills weekly up to cap of 2.
 */
class ShieldService
{
    public const MAX_SHIELDS = 2;
    public const REFILL_DAYS = 7;

    /** @return array{shields:int, refilled:bool} */
    public function refillIfDue(User $user): array
    {
        $today = Carbon::today();
        $last = $user->shield_last_refill ? Carbon::parse($user->shield_last_refill) : null;
        if ($last === null || $last->diffInDays($today) >= self::REFILL_DAYS) {
            $newShields = min(self::MAX_SHIELDS, (int) $user->streak_shields + 1);
            $changed = $newShields !== (int) $user->streak_shields;
            $user->streak_shields = $newShields;
            $user->shield_last_refill = now();
            $user->save();
            return ['shields' => $newShields, 'refilled' => $changed];
        }
        return ['shields' => (int) $user->streak_shields, 'refilled' => false];
    }

    /** @return array{ok:bool, shields_remaining:int, message:string} */
    public function use(User $user): array
    {
        if ((int) $user->streak_shields <= 0) {
            return ['ok' => false, 'shields_remaining' => 0, 'message' => '護盾用完了，下週一補發 🛡️'];
        }
        $remaining = (int) $user->streak_shields - 1;
        $user->streak_shields = $remaining;
        $user->last_active_date = Carbon::today()->toDateString();
        $user->save();
        return ['ok' => true, 'shields_remaining' => $remaining, 'message' => '護盾已使用，連續紀錄守住了 🛡️✨'];
    }
}
