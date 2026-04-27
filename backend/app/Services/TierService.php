<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;

/**
 * Translated from ai-game/src/services/tier.ts.
 *
 * Two orthogonal concerns:
 *   1. membership_tier: 'public' | 'fp_lifetime' (FP web members → lifetime)
 *   2. subscription_type: 'none' | 'app_monthly' | 'app_yearly'
 *
 * Ref code formats:
 *   - FP-* / JR-*  → membership_tier=fp_lifetime
 *   - APP-MONTH-*  → subscription_type=app_monthly (dev/QA mock)
 *   - APP-YEAR-*   → subscription_type=app_yearly (dev/QA mock)
 */
class TierService
{
    public const TIER_ORDER = ['public', 'fp_lifetime'];

    public const TIER_XP_MULTIPLIER = ['public' => 1.0, 'fp_lifetime' => 1.5];

    /** @return array{target:string, value:string, reason:string}|null */
    public function classifyRefCode(?string $code): ?array
    {
        if (! $code) return null;
        $trimmed = strtoupper(trim($code));
        if ($trimmed === '') return null;

        if (preg_match('/^FP-[A-Z0-9-]+$/', $trimmed)) {
            return ['target' => 'membership', 'value' => 'fp_lifetime', 'reason' => 'fp_web_signup'];
        }
        if (preg_match('/^JR-[A-Z0-9-]+$/', $trimmed)) {
            return ['target' => 'membership', 'value' => 'fp_lifetime', 'reason' => 'legacy_retail'];
        }
        if (preg_match('/^APP-(TEST|MONTH|MONTHLY|M)-/', $trimmed) || $trimmed === 'APP-TEST2026') {
            return ['target' => 'subscription', 'value' => 'app_monthly', 'reason' => 'app_subscription_mock'];
        }
        if (preg_match('/^APP-(YEAR|YEARLY|Y)-/', $trimmed)) {
            return ['target' => 'subscription', 'value' => 'app_yearly', 'reason' => 'app_subscription_mock'];
        }
        return null;
    }

    public function tierRank(string $tier): int
    {
        $idx = array_search($tier, self::TIER_ORDER, true);
        return $idx === false ? 0 : (int) $idx;
    }

    /** Apply a ref-code-driven upgrade. Never downgrades. */
    public function applyRefCode(User $user, string $refCode): array
    {
        $cls = $this->classifyRefCode($refCode);
        if (! $cls) abort(422, 'INVALID_REF_CODE');

        $prevTier = $user->membership_tier ?? 'public';
        $prevSub = $user->subscription_type ?? 'none';
        $newTier = $prevTier;
        $newSub = $prevSub;

        if ($cls['target'] === 'membership') {
            if ($this->tierRank($cls['value']) > $this->tierRank($prevTier)) {
                $newTier = $cls['value'];
            }
        } else {
            // fp_lifetime users don't need a subscription.
            if ($prevTier !== 'fp_lifetime') $newSub = $cls['value'];
        }

        $user->membership_tier = $newTier;
        $user->subscription_type = $newSub;
        $user->fp_ref_code = strtoupper(trim($refCode));
        $user->tier_verified_at = Carbon::now();
        if ($newSub !== $prevSub && $newSub !== 'none') {
            $user->subscription_expires_at_iso = $newSub === 'app_yearly'
                ? Carbon::now()->addYear()
                : Carbon::now()->addMonth();
        }
        $user->save();

        return [
            'user_id' => $user->id,
            'previous_tier' => $prevTier,
            'new_tier' => $newTier,
            'previous_subscription' => $prevSub,
            'new_subscription' => $newSub,
            'upgraded' => $newTier !== $prevTier || $newSub !== $prevSub,
            'reason' => $cls['reason'],
        ];
    }

    /** Admin override — set arbitrary tier (must be in TIER_ORDER). */
    public function adminSetTier(User $user, string $tier, string $reason = 'admin_manual'): array
    {
        if (! in_array($tier, self::TIER_ORDER, true)) abort(422, 'INVALID_TIER');
        $prevTier = $user->membership_tier ?? 'public';
        $user->membership_tier = $tier;
        $user->tier_verified_at = Carbon::now();
        $user->save();
        return [
            'user_id' => $user->id,
            'previous_tier' => $prevTier,
            'new_tier' => $tier,
            'upgraded' => $this->tierRank($tier) > $this->tierRank($prevTier),
            'reason' => $reason,
        ];
    }

    /** Mock IAP — sets subscription + 30/365d expiry. Replaced by real receipt validation later. */
    public function mockSubscribe(User $user, string $type): array
    {
        if (! in_array($type, ['none', 'app_monthly', 'app_yearly'], true)) {
            abort(422, 'INVALID_SUBSCRIPTION');
        }
        $prevSub = $user->subscription_type ?? 'none';
        $user->subscription_type = $type;
        $user->subscription_expires_at_iso = match ($type) {
            'app_monthly' => Carbon::now()->addDays(30),
            'app_yearly' => Carbon::now()->addDays(365),
            default => null,
        };
        $user->save();
        return [
            'user_id' => $user->id,
            'previous_subscription' => $prevSub,
            'new_subscription' => $type,
            'expires_at' => $user->subscription_expires_at_iso?->toIso8601String(),
        ];
    }

    /** E-commerce webhook payload → fp_lifetime upgrade if user found. */
    public function applyEcommerceOrder(array $payload): ?array
    {
        if (empty($payload['order_id'])) abort(422, 'MISSING_ORDER_ID');
        $user = null;
        if (! empty($payload['user_id'])) {
            $user = User::find($payload['user_id']);
        }
        if (! $user && ! empty($payload['email'])) {
            $user = User::where('email', $payload['email'])->first();
        }
        if (! $user) return null;

        $prevTier = $user->membership_tier ?? 'public';
        if ($this->tierRank('fp_lifetime') <= $this->tierRank($prevTier)) {
            return ['user_id' => $user->id, 'upgraded' => false, 'reason' => 'no_change'];
        }
        $user->membership_tier = 'fp_lifetime';
        $user->fp_ref_code = $user->fp_ref_code ?? "WEB-{$payload['order_id']}";
        $user->tier_verified_at = Carbon::now();
        $user->save();

        return ['user_id' => $user->id, 'upgraded' => true, 'reason' => 'ecommerce_webhook'];
    }
}
