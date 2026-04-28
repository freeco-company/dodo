<?php

namespace App\Services\Iap;

use App\Exceptions\IapNotConfiguredException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase E — Apple App Store Server API receipt verifier (skeleton).
 *
 * Three modes, gated by env:
 *   1. Real (IAP_APPLE_SHARED_SECRET set, IAP_STUB_MODE != true):
 *      POST to https://buy.itunes.apple.com/verifyReceipt → fall back to
 *      sandbox on status=21007. Parse latest_receipt_info → DTO.
 *      ⚠ This skeleton wires the HTTP shape but is NOT exercised by tests
 *      (we don't want flaky / live API calls in CI). Production will need
 *      a real fixture-based test added before going live.
 *
 *   2. Stub (IAP_STUB_MODE=true): tokens prefixed `STUB_APPLE_M_*` →
 *      app_monthly, `STUB_APPLE_Y_*` → app_yearly. Lets RN dev / E2E run
 *      without hitting Apple. Anything else: throw stub mismatch.
 *
 *   3. Not configured: throw IapNotConfiguredException → 503 to client.
 */
class AppleReceiptVerifier
{
    private const PROD_URL = 'https://buy.itunes.apple.com/verifyReceipt';

    private const SANDBOX_URL = 'https://sandbox.itunes.apple.com/verifyReceipt';

    public function verify(string $receiptData): IapVerificationResult
    {
        if ($this->isStubMode()) {
            return $this->stubVerify($receiptData);
        }

        $sharedSecret = (string) config('services.iap.apple.shared_secret');
        if ($sharedSecret === '') {
            throw new IapNotConfiguredException(
                'apple',
                'Set IAP_APPLE_SHARED_SECRET (App Store Connect → App-Specific Shared Secret) or IAP_STUB_MODE=true.'
            );
        }

        // Real verifyReceipt path — kept minimal; production will replace with
        // App Store Server API v1 (signed JWT) once we move off the legacy endpoint.
        $payload = ['receipt-data' => $receiptData, 'password' => $sharedSecret, 'exclude-old-transactions' => true];

        $response = Http::asJson()->post(self::PROD_URL, $payload)->json();
        if (($response['status'] ?? null) === 21007) {
            // Sandbox receipt sent to prod — retry sandbox.
            $response = Http::asJson()->post(self::SANDBOX_URL, $payload)->json();
        }

        $status = $response['status'] ?? null;
        if ($status !== 0) {
            Log::warning('[iap:apple] verifyReceipt failed', ['status' => $status]);
            throw new \RuntimeException("Apple verifyReceipt failed (status {$status}).");
        }

        return $this->mapResponse($response);
    }

    private function isStubMode(): bool
    {
        return (bool) config('services.iap.stub_mode');
    }

    private function stubVerify(string $receiptData): IapVerificationResult
    {
        // Format: STUB_APPLE_<M|Y>_<txid>
        if (! preg_match('/^STUB_APPLE_(M|Y)_(.+)$/', $receiptData, $m)) {
            throw new \RuntimeException(
                'Apple stub mode expects receipts shaped STUB_APPLE_M_<id> or STUB_APPLE_Y_<id>.'
            );
        }
        $isYearly = $m[1] === 'Y';
        $now = Carbon::now();

        return new IapVerificationResult(
            provider: 'apple',
            originalTransactionId: 'apple-otid-'.$m[2],
            productId: $isYearly ? 'dodo.subscription.yearly' : 'dodo.subscription.monthly',
            purchasedAt: $now,
            expiresAt: $isYearly ? $now->copy()->addYear() : $now->copy()->addMonth(),
            isSandbox: true,
            rawPayload: ['stub' => true, 'plan' => $isYearly ? 'app_yearly' : 'app_monthly'],
        );
    }

    /** @param  array<string, mixed>  $response */
    private function mapResponse(array $response): IapVerificationResult
    {
        $info = ($response['latest_receipt_info'][0] ?? null);
        if (! is_array($info)) {
            throw new \RuntimeException('Apple receipt missing latest_receipt_info.');
        }

        return new IapVerificationResult(
            provider: 'apple',
            originalTransactionId: (string) ($info['original_transaction_id'] ?? ''),
            productId: (string) ($info['product_id'] ?? ''),
            purchasedAt: Carbon::createFromTimestampMs((int) ($info['purchase_date_ms'] ?? 0)),
            expiresAt: Carbon::createFromTimestampMs((int) ($info['expires_date_ms'] ?? 0)),
            isSandbox: ($response['environment'] ?? '') === 'Sandbox',
            rawPayload: $response,
        );
    }
}
