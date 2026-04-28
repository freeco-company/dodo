<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Iap\IapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Phase E — Apple App Store Server Notifications v2 endpoint.
 *
 * Apple POSTs a `signedPayload` (JWS, three-segment JWT signed with Apple's
 * P256 key chain). Real impl needs to:
 *   1. Decode header → verify x5c chain against Apple's root certs.
 *   2. Verify signature (ES256) over header.payload.
 *   3. Decode payload.signedTransactionInfo / signedRenewalInfo (each its
 *      own nested JWS).
 *
 * Phase E ships:
 *   - Stub mode (IAP_STUB_MODE=true): expects flat JSON body with
 *     `notificationUUID`, `notificationType`, `data` and trusts it.
 *   - Real mode without firebase/jwt verify lib loaded: returns 503.
 *
 * In *all* modes we persist via IapService::applyServerNotification which
 * is idempotent on notificationUUID — duplicate retries cost a single
 * indexed lookup.
 */
class AppleNotificationController extends Controller
{
    public function __construct(private readonly IapService $iap) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! (bool) config('services.iap.stub_mode')) {
            // Real path requires JWS verification. Don't pretend.
            Log::warning('[iap:apple-asn] real signature path not implemented yet — refusing to process.');

            return response()->json(['error' => 'IAP_NOT_CONFIGURED'], 503);
        }

        $payload = $request->validate([
            'notificationUUID' => ['required', 'string', 'max:128'],
            'notificationType' => ['required', 'string', 'max:64'],
            'data' => ['nullable', 'array'],
            'signature' => ['nullable', 'string'],
        ]);

        // Stub-mode signature: caller must include `signature: STUB_VALID`.
        // Mismatch returns 401 so tests can exercise the negative path.
        if (($payload['signature'] ?? null) !== 'STUB_VALID') {
            return response()->json(['error' => 'INVALID_SIGNATURE'], 401);
        }

        $sub = $this->iap->applyServerNotification(
            'apple',
            $payload['notificationUUID'],
            $payload['notificationType'],
            $payload['data'] ?? [],
        );

        return response()->json([
            'ok' => true,
            'subscription_state' => $sub?->state,
        ]);
    }
}
