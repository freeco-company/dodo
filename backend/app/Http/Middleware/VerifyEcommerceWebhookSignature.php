<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound 婕樂纖 ecommerce webhook signature verifier.
 *
 * Headers (publisher 母艦 sends):
 *   - X-Pandora-Timestamp: ISO-8601 UTC
 *   - X-Pandora-Signature: "sha256=" + hex of HMAC-SHA256(secret, "{timestamp}.{body}")
 *
 * Fail-closed:
 *   - secret env empty           → 503 (better than silently accepting)
 *   - missing headers            → 401
 *   - timestamp out of window    → 401
 *   - signature mismatch         → 401
 *
 * Replay defense: timestamp window only (no nonce table) — ecommerce order
 * webhooks are uniquely keyed by `order_id` and the underlying tier upgrade
 * is forward-only / idempotent (`applyEcommerceOrder` won't downgrade), so
 * a replay within the window can't escalate state. If we add anti-replay
 * later, model after VerifyGamificationWebhookSignature's nonce table.
 */
class VerifyEcommerceWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.meal_ecommerce_webhook.secret');
        if ($secret === '') {
            Log::error('[EcommerceWebhook] MEAL_ECOMMERCE_WEBHOOK_SECRET not configured');

            return response()->json(['error' => 'webhook secret not configured'], 503);
        }

        $timestamp = (string) $request->header('X-Pandora-Timestamp', '');
        $signature = (string) $request->header('X-Pandora-Signature', '');
        if ($timestamp === '' || $signature === '') {
            return response()->json(['error' => 'missing signature headers'], 401);
        }

        $window = (int) config('services.meal_ecommerce_webhook.window_seconds', 300);
        try {
            $ts = Carbon::parse($timestamp);
        } catch (\Exception) {
            return response()->json(['error' => 'invalid timestamp'], 401);
        }
        if (abs(Carbon::now()->diffInSeconds($ts, false)) > $window) {
            return response()->json(['error' => 'timestamp out of window'], 401);
        }

        $body = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'signature mismatch'], 401);
        }

        return $next($request);
    }
}
