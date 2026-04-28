<?php

namespace App\Services\Ecpay;

use App\Models\EcpayCallback;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\SubscriptionStateMachine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Phase E — minimal ECPay (綠界) client + signature verifier.
 *
 * **Scope (intentionally narrow)**:
 *   - sign($params) — produce CheckMacValue with ECPay's URL-encoded sort
 *     scheme and SHA-256 EncryptType.
 *   - verify($params) — same algorithm, constant-time compare.
 *   - applyNotification($params) — idempotent persistence + state-machine
 *     transition for PeriodReturnURL hits.
 *
 * **Out of scope** (deferred):
 *   - The actual AioCheckOut HTML form bridge (用戶端 redirect to ECPay) —
 *     RN App typically opens this in an in-app browser; backend only needs
 *     to provide the params + signature, and the front end POSTs to ECPay.
 *   - Refund / void API — to be added when admin tooling lands.
 *
 * Signature algo reference:
 *   1. ksort params (ASCII)
 *   2. join HashKey=...&k=v&k=v&...&HashIV=...
 *   3. urlencode(...) per ECPay's flavour:
 *        rawurlencode + lowercase + ECPay-specific replacements
 *   4. SHA-256 → uppercase hex
 *
 * Tests use a pinned (HashKey=pwFHCqoQZGmho4w6, HashIV=EkRm7iFT261dpevs)
 * pair from ECPay's own sample so we can compare against their docs without
 * burning a real key.
 */
class EcpayClient
{
    public function __construct(
        private readonly SubscriptionStateMachine $stateMachine,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public function sign(array $params): string
    {
        $hashKey = $this->hashKey();
        $hashIv = $this->hashIv();
        if ($hashKey === '' || $hashIv === '') {
            throw new RuntimeException('ECPay HashKey/HashIV not configured.');
        }

        // Drop the existing CheckMacValue if any.
        unset($params['CheckMacValue']);

        // Sort by key (case-insensitive ASCII per ECPay).
        uksort($params, fn ($a, $b) => strcasecmp($a, $b));

        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = $k.'='.$v;
        }
        $raw = 'HashKey='.$hashKey.'&'.implode('&', $pairs).'&HashIV='.$hashIv;
        $encoded = $this->ecpayUrlEncode($raw);

        return strtoupper(hash('sha256', $encoded));
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function verify(array $params): bool
    {
        $provided = (string) ($params['CheckMacValue'] ?? '');
        if ($provided === '') {
            return false;
        }
        $expected = $this->sign($params);

        return hash_equals($expected, $provided);
    }

    /**
     * Persist + apply a (period) notify callback. Idempotent on
     * (merchant_trade_no, rtn_code, callback_kind). Returns the EcpayCallback row.
     *
     * @param  array<string, mixed>  $params
     */
    public function applyNotification(array $params, string $callbackKind = 'period'): EcpayCallback
    {
        $signatureValid = $this->verify($params);

        // We persist even on signature mismatch (forensic), but only act on valid ones.
        $callback = EcpayCallback::query()->updateOrCreate(
            [
                'merchant_trade_no' => (string) ($params['MerchantTradeNo'] ?? ''),
                'rtn_code' => (string) ($params['RtnCode'] ?? ''),
                'callback_kind' => $callbackKind,
            ],
            [
                'trade_no' => $params['TradeNo'] ?? null,
                'rtn_msg' => $params['RtnMsg'] ?? null,
                'raw_payload' => $params,
                'signature_valid' => $signatureValid,
            ]
        );

        if (! $signatureValid) {
            Log::warning('[ecpay] signature mismatch — payload stored but ignored', [
                'merchant_trade_no' => $callback->merchant_trade_no,
            ]);

            return $callback;
        }
        if ($callback->processed_at) {
            // Already applied — pure idempotent no-op.
            return $callback;
        }
        if ((string) ($params['RtnCode'] ?? '') !== '1') {
            // Non-success notify (e.g. RtnCode=10100073 declined). Mark processed but skip.
            $callback->processed_at = Carbon::now();
            $callback->save();

            return $callback;
        }

        // Resolve subscription via MerchantTradeNo == provider_subscription_id
        // (we always use the first-auth MerchantTradeNo as the stable id).
        $sub = Subscription::query()
            ->where('provider', 'ecpay')
            ->where('provider_subscription_id', $callback->merchant_trade_no)
            ->first();

        if ($sub) {
            $now = Carbon::now();
            $period = $sub->plan === 'app_yearly' ? $now->copy()->addYear() : $now->copy()->addMonth();
            $this->stateMachine->activate($sub, $now, $period, $params);
        } else {
            Log::info('[ecpay] valid notify with no matching subscription row', [
                'merchant_trade_no' => $callback->merchant_trade_no,
            ]);
        }

        $callback->processed_at = Carbon::now();
        $callback->save();

        return $callback;
    }

    /**
     * Helper for tests / order-creation flow: bind a Subscription row to a
     * MerchantTradeNo before redirecting the user to ECPay. Without this
     * the period notify can't find which user to credit.
     */
    public function registerOrder(User $user, string $merchantTradeNo, string $plan): Subscription
    {
        $sub = $this->stateMachine->findOrInitialise(
            $user,
            'ecpay',
            $merchantTradeNo,
            null,
            $plan,
        );
        if (! $sub->exists) {
            $sub->save();
        }

        return $sub;
    }

    private function hashKey(): string
    {
        return (string) config('services.ecpay.hash_key');
    }

    private function hashIv(): string
    {
        return (string) config('services.ecpay.hash_iv');
    }

    /**
     * ECPay's URL encoding twist: PHP urlencode (NOT rawurlencode), then
     * lowercase, then revert a fixed set of characters. Matches the official
     * sample at https://developers.ecpay.com.tw/?p=2904.
     */
    private function ecpayUrlEncode(string $input): string
    {
        $encoded = strtolower(urlencode($input));
        // ECPay-specific substitutions (these characters are not encoded in their reference impl)
        $replace = [
            '%2d' => '-',
            '%5f' => '_',
            '%2e' => '.',
            '%21' => '!',
            '%2a' => '*',
            '%28' => '(',
            '%29' => ')',
        ];

        return strtr($encoded, $replace);
    }
}
