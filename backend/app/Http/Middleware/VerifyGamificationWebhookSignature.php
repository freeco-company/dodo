<?php

namespace App\Http\Middleware;

use App\Models\GamificationWebhookNonce;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gamification webhook signature verifier — matches py-service's outbox dispatcher
 * (see py-service `app/gamification/outbox.py::_sign`).
 *
 * Headers from py-service:
 *   - X-Pandora-Timestamp: ISO-8601 UTC
 *   - X-Pandora-Nonce: random hex (32 chars)
 *   - X-Pandora-Signature: "sha256=" + hex of HMAC-SHA256(secret, timestamp.nonce.body)
 *
 * Replay protection: read `event_id` from the JSON body and INSERT into
 * `gamification_webhook_nonces`. Duplicate INSERT → 200 short-circuit (publisher
 * already considers this delivered, telling them otherwise loops forever).
 *
 * NOTE: This signing scheme intentionally differs from `VerifyIdentityWebhookSignature`
 *   (which uses `{unix_timestamp}.{event_id}.{body}` and a hex-only signature).
 *   Both schemes are valid; we keep the py-service one byte-identical to avoid
 *   tweaking py-service ADR-007 conventions for the new receiver.
 */
class VerifyGamificationWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pandora_gamification.webhook_secret');
        if ($secret === '') {
            Log::error('[GamificationWebhook] missing PANDORA_GAMIFICATION_WEBHOOK_SECRET');

            return response()->json(['error' => 'webhook secret not configured'], 500);
        }

        $timestamp = (string) $request->header('X-Pandora-Timestamp', '');
        $nonce = (string) $request->header('X-Pandora-Nonce', '');
        $signature = (string) $request->header('X-Pandora-Signature', '');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return response()->json(['error' => 'missing signature headers'], 401);
        }

        $window = (int) config('services.pandora_gamification.webhook_window_seconds', 300);
        try {
            $ts = Carbon::parse($timestamp);
        } catch (\Exception) {
            return response()->json(['error' => 'invalid timestamp'], 401);
        }
        if (abs(Carbon::now()->diffInSeconds($ts, false)) > $window) {
            return response()->json(['error' => 'timestamp out of window'], 401);
        }

        $body = $request->getContent();
        $expected = 'sha256='.hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'signature mismatch'], 401);
        }

        $eventId = (string) ($request->json('event_id') ?? '');
        $eventType = (string) ($request->json('event_type') ?? '');
        if ($eventId === '' || $eventType === '') {
            return response()->json(['error' => 'missing event_id or event_type'], 422);
        }

        try {
            GamificationWebhookNonce::create([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json(['status' => 'duplicate', 'event_id' => $eventId], 200);
            }
            throw $e;
        }

        return $next($request);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $code = (string) $e->getCode();
        $msg = $e->getMessage();

        return $code === '23000'
            || str_contains($msg, '1062')
            || str_contains($msg, 'UNIQUE constraint failed');
    }
}
