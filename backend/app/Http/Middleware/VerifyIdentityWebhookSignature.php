<?php

namespace App\Http\Middleware;

use App\Models\IdentityWebhookNonce;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 朵朵端 webhook 簽章驗證 — 與母艦完全一致設計。
 *
 * 簽章基底：{timestamp}.{event_id}.{raw_body}
 * Headers: X-Pandora-Event-Id / X-Pandora-Timestamp / X-Pandora-Signature
 *
 * Replay → 200 noop（告訴 publisher 已處理）；其餘失敗 → 401。
 */
class VerifyIdentityWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pandora_core.webhook_secret');
        if ($secret === '') {
            Log::error('[IdentityWebhook] missing PANDORA_CORE_WEBHOOK_SECRET');

            return response()->json(['error' => 'webhook secret not configured'], 500);
        }

        $eventId = (string) $request->header('X-Pandora-Event-Id', '');
        $timestamp = (string) $request->header('X-Pandora-Timestamp', '');
        $signature = (string) $request->header('X-Pandora-Signature', '');

        if ($eventId === '' || $timestamp === '' || $signature === '') {
            return response()->json(['error' => 'missing signature headers'], 401);
        }

        $window = (int) config('services.pandora_core.webhook_window_seconds', 300);
        if (abs(time() - (int) $timestamp) > $window) {
            return response()->json(['error' => 'timestamp out of window'], 401);
        }

        $body = $request->getContent();
        $expected = hash_hmac('sha256', "{$timestamp}.{$eventId}.{$body}", $secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'signature mismatch'], 401);
        }

        try {
            IdentityWebhookNonce::create([
                'event_id' => $eventId,
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
