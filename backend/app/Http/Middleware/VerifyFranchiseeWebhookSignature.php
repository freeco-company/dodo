<?php

namespace App\Http\Middleware;

use App\Models\FranchiseeWebhookNonce;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 母艦 (pandora-js-store) → 朵朵 franchisee 同步 webhook 簽章驗證。
 *
 * 與 VerifyIdentityWebhookSignature 結構一致 — 差別：
 *   - secret 走 services.mothership.franchise_webhook_secret（與 platform identity 不同）
 *   - 用獨立 nonce 表 franchisee_webhook_nonces 避免 event_id 與 identity 撞車
 *
 * 簽章基底：{timestamp}.{event_id}.{raw_body}
 * Headers: X-Pandora-Event-Id / X-Pandora-Timestamp / X-Pandora-Signature
 */
class VerifyFranchiseeWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.mothership.franchise_webhook_secret');
        if ($secret === '') {
            Log::error('[FranchiseeWebhook] missing MOTHERSHIP_FRANCHISE_WEBHOOK_SECRET');

            return response()->json(['error' => 'webhook secret not configured'], 500);
        }

        $eventId = (string) $request->header('X-Pandora-Event-Id', '');
        $timestamp = (string) $request->header('X-Pandora-Timestamp', '');
        $signature = (string) $request->header('X-Pandora-Signature', '');

        if ($eventId === '' || $timestamp === '' || $signature === '') {
            return response()->json(['error' => 'missing signature headers'], 401);
        }

        $window = (int) config('services.mothership.webhook_window_seconds', 300);
        if (abs(time() - (int) $timestamp) > $window) {
            return response()->json(['error' => 'timestamp out of window'], 401);
        }

        $body = $request->getContent();
        $expected = hash_hmac('sha256', "{$timestamp}.{$eventId}.{$body}", $secret);
        if (! hash_equals($expected, $signature)) {
            return response()->json(['error' => 'signature mismatch'], 401);
        }

        try {
            FranchiseeWebhookNonce::create([
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
