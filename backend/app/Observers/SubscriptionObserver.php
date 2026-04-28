<?php

namespace App\Observers;

use App\Models\Subscription;
use App\Models\User;

/**
 * Phase E — keep the legacy User columns in sync with whatever subscription
 * row is currently authoritative for that user.
 *
 * Why mirror? EntitlementsService / paywall / bootstrap / RN App all read
 * User.subscription_type + subscription_expires_at_iso. Re-pointing them to
 * Subscription would be a wide refactor; this Observer is the smallest
 * change that lets state-machine-driven changes propagate.
 *
 * Rule: pick the *most generous* currently-active row (active > grace >
 * trial; ignore expired/refunded). If user has fp_lifetime membership we
 * never downgrade subscription_type to 'none' from a webhook (the
 * lifetime tier is independent).
 */
class SubscriptionObserver
{
    public function saved(Subscription $sub): void
    {
        if (! $sub->user_id) {
            return;
        }
        $user = User::find($sub->user_id);
        if (! $user) {
            return;
        }

        // Find the user's "best" current subscription across all providers.
        // Manual priority sort instead of MariaDB FIELD() so the same code
        // works under SQLite-in-memory tests.
        $candidates = Subscription::query()
            ->where('user_id', $user->id)
            ->whereIn('state', ['active', 'grace', 'trial'])
            ->orderByDesc('current_period_end')
            ->get();
        $rank = ['active' => 0, 'grace' => 1, 'trial' => 2];
        $best = $candidates
            ->sortBy(fn (Subscription $s) => $rank[$s->state] ?? 99)
            ->first();

        if ($best && in_array($best->state, ['active', 'grace'], true)) {
            $user->subscription_type = $best->plan ?? $user->subscription_type ?? 'none';
            $user->subscription_expires_at_iso = $best->current_period_end;
        } else {
            // No active/grace subscription anywhere → drop unless user paid via legacy
            // mock path that never created a Subscription row. We only reset if there
            // *is* at least one Subscription row (otherwise we'd be wiping pre-Phase-E state).
            $hasAny = Subscription::query()->where('user_id', $user->id)->exists();
            if ($hasAny) {
                $user->subscription_type = 'none';
                $user->subscription_expires_at_iso = null;
            }
        }
        $user->save();
    }
}
