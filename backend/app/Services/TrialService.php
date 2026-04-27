<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Translated from ai-game/src/services/trial.ts.
 *
 * 7-day free trial. Drives "wow moment" before paywall.
 *  - register      → trial_started_at = now, trial_expires_at = now + 7d
 *  - day 7         → expires; entitlements drop
 *  - subscribes    → flags stay (analytics), subscription wins
 */
class TrialService
{
    public const DEFAULT_TRIAL_DAYS = 7;

    public function start(User $user, int $days = self::DEFAULT_TRIAL_DAYS): Carbon
    {
        $now = Carbon::now();
        $expires = $now->copy()->addDays($days);
        $user->trial_started_at = $now;
        $user->trial_expires_at = $expires;
        $user->save();
        return $expires;
    }

    /** Extend by N days. If currently active, extend from current expiry; else from now. */
    public function extend(User $user, int $addDays): Carbon
    {
        $now = Carbon::now();
        $base = ($user->trial_expires_at && $user->trial_expires_at->gt($now))
            ? $user->trial_expires_at->copy()
            : $now->copy();
        $expires = $base->addDays($addDays);
        $user->trial_expires_at = $expires;
        if (! $user->trial_started_at) {
            $user->trial_started_at = $now;
        }
        $user->save();
        return $expires;
    }

    public function isOnTrial(User $user): bool
    {
        return $user->trial_expires_at && $user->trial_expires_at->isFuture();
    }

    public function daysLeft(User $user): int
    {
        if (! $user->trial_expires_at) return 0;
        $diff = Carbon::now()->diffInSeconds($user->trial_expires_at, false);
        if ($diff <= 0) return 0;
        return (int) ceil($diff / 86400);
    }

    /** @return array{state:string, days_left:int, expires_at:?string} */
    public function status(User $user): array
    {
        if (! $user->trial_started_at) {
            return ['state' => 'never_started', 'days_left' => 0, 'expires_at' => null];
        }
        if ($user->subscription_type && $user->subscription_type !== 'none') {
            return [
                'state' => 'converted',
                'days_left' => 0,
                'expires_at' => $user->trial_expires_at?->toIso8601String(),
            ];
        }
        $left = $this->daysLeft($user);
        return [
            'state' => $left > 0 ? 'active' : 'expired',
            'days_left' => $left,
            'expires_at' => $user->trial_expires_at?->toIso8601String(),
        ];
    }
}
