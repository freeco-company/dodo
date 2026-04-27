<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Translated from ai-game/src/services/entitlements.ts.
 *
 * Single source of truth for "what can this user do?". Free users get 3
 * island visits / calendar month. fp_lifetime + active subscription = unlimited.
 */
class EntitlementsService
{
    public const FREE_ISLAND_VISITS_PER_MONTH = 3;

    public const SUBSCRIPTION_PRICING = [
        'app_monthly' => ['price' => 99, 'currency' => 'TWD', 'period' => 'month', 'label' => 'NT$99 / 月'],
        'app_yearly' => ['price' => 790, 'currency' => 'TWD', 'period' => 'year', 'label' => 'NT$790 / 年（約 33% off）'],
    ];

    private function currentMonthKey(): string
    {
        return Carbon::now()->startOfMonth()->toDateString();
    }

    private function nextMonthResetIso(): string
    {
        return Carbon::now()->addMonth()->startOfMonth()->toDateString();
    }

    private function rolloverIfNeeded(User $user): void
    {
        $currentMonth = $this->currentMonthKey();
        if ((string) $user->island_visits_reset_at?->toDateString() !== $currentMonth) {
            $user->island_visits_used = 0;
            $user->island_visits_reset_at = $currentMonth;
            $user->save();
        }
    }

    /** @return array<string,mixed> */
    public function get(User $user): array
    {
        $this->rolloverIfNeeded($user);
        $user->refresh();

        $tier = $user->membership_tier;
        $sub = $user->subscription_type;
        $subActive = $sub !== 'none' && (
            ! $user->subscription_expires_at_iso ||
            $user->subscription_expires_at_iso->isFuture()
        );
        $unlimited = $tier === 'fp_lifetime' || $subActive;
        $fpExclusive = $tier === 'fp_lifetime';

        $total = $unlimited ? 0 : self::FREE_ISLAND_VISITS_PER_MONTH;
        $used = $unlimited ? 0 : (int) $user->island_visits_used;
        $remaining = $unlimited ? 0 : max(0, $total - $used);

        return [
            'tier' => $tier,
            'subscription' => $sub,
            'subscription_expires_at_iso' => $user->subscription_expires_at_iso?->toIso8601String(),
            'unlimited_island' => $unlimited,
            'fp_exclusive' => $fpExclusive,
            'island_quota_total' => $total,
            'island_quota_used' => $used,
            'island_quota_remaining' => $remaining,
            'island_quota_reset_at' => $this->nextMonthResetIso(),
            'pricing' => self::SUBSCRIPTION_PRICING,
            'fp_web_signup_url' => 'https://pandora.js-store.com.tw/join',
        ];
    }
}
