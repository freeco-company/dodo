<?php

namespace App\Services\Iap;

use App\Exceptions\IapNotConfiguredException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Phase E — Google Play Developer API subscription verifier (skeleton).
 *
 * Real impl would use a Service Account JSON to mint an OAuth2 token, then
 * GET https://androidpublisher.googleapis.com/androidpublisher/v3/applications/
 *     {pkg}/purchases/subscriptionsv2/tokens/{token}.
 *
 * For Phase E we only wire stub + the not-configured failure mode. The real
 * HTTP call is left as a TODO (would require googleapis/google-api-php-client
 * or kreait/firebase-php — neither is in composer.json yet, and we don't add
 * deps without explicit approval).
 *
 * Stub format: STUB_GOOGLE_<M|Y>_<token>
 */
class GoogleReceiptVerifier
{
    public function verify(string $packageName, string $productId, string $purchaseToken): IapVerificationResult
    {
        if ($this->isStubMode()) {
            return $this->stubVerify($purchaseToken);
        }

        $serviceAccountJson = (string) config('services.iap.google.service_account_json');
        if ($serviceAccountJson === '') {
            throw new IapNotConfiguredException(
                'google',
                'Set IAP_GOOGLE_SERVICE_ACCOUNT_JSON (path to service account file) or IAP_STUB_MODE=true.'
            );
        }

        Log::warning('[iap:google] real path not implemented yet — install google-api-php-client to enable.');
        throw new \RuntimeException('Google Play subscription verifier real path is TODO (Phase E ships stub-only).');
    }

    private function isStubMode(): bool
    {
        return (bool) config('services.iap.stub_mode');
    }

    private function stubVerify(string $token): IapVerificationResult
    {
        if (! preg_match('/^STUB_GOOGLE_(M|Y)_(.+)$/', $token, $m)) {
            throw new \RuntimeException(
                'Google stub mode expects tokens shaped STUB_GOOGLE_M_<id> or STUB_GOOGLE_Y_<id>.'
            );
        }
        $isYearly = $m[1] === 'Y';
        $now = Carbon::now();

        return new IapVerificationResult(
            provider: 'google',
            originalTransactionId: 'google-otid-'.$m[2],
            productId: $isYearly ? 'dodo.subscription.yearly' : 'dodo.subscription.monthly',
            purchasedAt: $now,
            expiresAt: $isYearly ? $now->copy()->addYear() : $now->copy()->addMonth(),
            isSandbox: true,
            rawPayload: ['stub' => true, 'plan' => $isYearly ? 'app_yearly' : 'app_monthly'],
        );
    }
}
