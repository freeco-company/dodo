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

    /**
     * SPEC-photo-ai-calorie-polish §5.1 — Free 用戶 3 次/天 拍照 AI 辨識。
     * 付費用戶 unlimited（與 island 同 unlimited gating 邏輯）。
     */
    public const FREE_PHOTO_AI_PER_DAY = 3;

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

    private function todayKey(): string
    {
        return Carbon::now('Asia/Taipei')->toDateString();
    }

    private function tomorrowResetIso(): string
    {
        return Carbon::now('Asia/Taipei')->addDay()->startOfDay()->toIso8601String();
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

    /**
     * Roll over the daily photo AI counter at Asia/Taipei midnight.
     * Idempotent: same-day re-call is a no-op; cross-day call zeros the counter.
     */
    private function rolloverPhotoAiIfNeeded(User $user): void
    {
        $today = $this->todayKey();
        if ((string) $user->photo_ai_reset_at?->toDateString() !== $today) {
            $user->photo_ai_used_today = 0;
            $user->photo_ai_reset_at = $today;
            $user->save();
        }
    }

    /**
     * SPEC §5.1 — pre-flight quota check. Returns true if the user may consume
     * one photo AI recognition NOW (and bumps the counter atomically). False
     * means quota exhausted; caller should return 402 + paywall payload.
     *
     * Paid users (unlimited) always pass without bumping (we don't count their
     * usage here — AiCostGuardService records the underlying $$ cost).
     */
    public function consumePhotoAiQuota(User $user): bool
    {
        if ($this->isUnlimited($user)) {
            return true;
        }
        $this->rolloverPhotoAiIfNeeded($user);
        $user->refresh();

        if ((int) $user->photo_ai_used_today >= self::FREE_PHOTO_AI_PER_DAY) {
            return false;
        }
        $user->photo_ai_used_today = ((int) $user->photo_ai_used_today) + 1;
        $user->save();

        return true;
    }

    /**
     * SPEC-fasting-timer §5 — public alias for paid-tier check.
     * Re-uses the same predicate as `isUnlimited` (any active sub or fp_lifetime).
     */
    public function isPaid(User $user): bool
    {
        return $this->isUnlimited($user);
    }

    private function isUnlimited(User $user): bool
    {
        $tier = $user->membership_tier;
        $sub = $user->subscription_type;
        $subActive = $sub !== 'none' && (
            ! $user->subscription_expires_at_iso ||
            $user->subscription_expires_at_iso->isFuture()
        );

        return $tier === 'fp_lifetime' || $subActive;
    }

    /** @return array<string,mixed> */
    public function get(User $user): array
    {
        $this->rolloverIfNeeded($user);
        $this->rolloverPhotoAiIfNeeded($user);
        $user->refresh();

        $tier = $user->membership_tier;
        $sub = $user->subscription_type;
        $unlimited = $this->isUnlimited($user);
        $fpExclusive = $tier === 'fp_lifetime';

        $total = $unlimited ? 0 : self::FREE_ISLAND_VISITS_PER_MONTH;
        $used = $unlimited ? 0 : (int) $user->island_visits_used;
        $remaining = $unlimited ? 0 : max(0, $total - $used);

        $photoTotal = $unlimited ? 0 : self::FREE_PHOTO_AI_PER_DAY;
        $photoUsed = $unlimited ? 0 : (int) $user->photo_ai_used_today;
        $photoRemaining = $unlimited ? 0 : max(0, $photoTotal - $photoUsed);

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
            // SPEC-photo-ai-calorie-polish — frontend uses photo_ai_quota_* to
            // pre-decide whether to take the camera shot or surface paywall.
            // Server still does the canonical check via consumePhotoAiQuota().
            'photo_ai_quota_total' => $photoTotal,
            'photo_ai_quota_used' => $photoUsed,
            'photo_ai_quota_remaining' => $photoRemaining,
            'photo_ai_quota_reset_at' => $this->tomorrowResetIso(),
            // SPEC-fasting-timer §5 — gating snapshot for frontend & FastingService.
            'fasting_advanced_modes' => $unlimited,
            'fasting_history_days' => $unlimited ? 0 : 7,
            // SPEC-healthkit-integration §6 — sleep / heart rate paid only;
            // history capped to 7 days for free.
            'health_paid_types' => $unlimited,
            'health_history_days' => $unlimited ? 0 : 7,
            'pricing' => self::SUBSCRIPTION_PRICING,
            'fp_web_signup_url' => 'https://pandora.js-store.com.tw/join',
        ];
    }
}
