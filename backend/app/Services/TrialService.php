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
        // Pre-launch security TODO: trial-fraud blacklist.
        //
        // Risk: a user can delete their account, re-register with a new email
        // and the same Apple ID / device, and farm an unlimited 7-day trial.
        //
        // Proper fix needs Apple Sign-In to be wired (Task C above —
        // OAuth flow PR) so we can match on the verified Apple
        // `original_transaction_id` (Apple's own per-user identifier that
        // survives re-install) and refuse to mint a fresh trial for the
        // same OTID. We also need a `trial_blacklist` table populated by
        // AccountDeletionService::purge().
        //
        // Today: no Apple OAuth + register rejects raw apple_id (Task C),
        // so there is no reliable cross-account identifier. Leaving this
        // unguarded for the launch and treating trial farming as a minor
        // pre-launch acceptable cost.
        //
        // @todo OAuth wiring PR — add OTID-based trial blacklist check here.

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
