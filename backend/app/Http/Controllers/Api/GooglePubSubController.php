<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Iap\IapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase E — Google Real-Time Developer Notifications endpoint.
 *
 * Pub/Sub push delivers:
 *   {
 *     "message": {
 *       "data": "<base64(JSON)>",
 *       "messageId": "...",
 *       "publishTime": "..."
 *     },
 *     "subscription": "projects/.../subscriptions/..."
 *   }
 *
 * Authentication is normally OIDC token in Authorization header (Pub/Sub
 * service account → audience = our endpoint). Phase E stub-mode trusts
 * the body; production must add OIDC verification middleware.
 */
class GooglePubSubController extends Controller
{
    public function __construct(private readonly IapService $iap) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! (bool) config('services.iap.stub_mode')) {
            return response()->json(['error' => 'IAP_NOT_CONFIGURED'], 503);
        }

        $envelope = $request->validate([
            'message' => ['required', 'array'],
            'message.messageId' => ['required', 'string', 'max:128'],
            'message.data' => ['required', 'string'],
        ]);

        $decoded = base64_decode($envelope['message']['data'], true);
        if ($decoded === false) {
            return response()->json(['error' => 'INVALID_BASE64'], 400);
        }
        /** @var array<string, mixed>|null $body */
        $body = json_decode($decoded, true);
        if (! is_array($body)) {
            return response()->json(['error' => 'INVALID_JSON'], 400);
        }

        $eventType = (string) ($body['notificationType'] ?? $body['subscriptionNotification']['notificationType'] ?? '');

        $sub = $this->iap->applyServerNotification(
            'google',
            $envelope['message']['messageId'],
            $eventType ?: null,
            $body,
        );

        return response()->json([
            'ok' => true,
            'subscription_state' => $sub?->state,
        ]);
    }
}
