<?php

namespace App\Http\Middleware;

use App\Models\LifecycleInvalidateNonce;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lifecycle cache-invalidate webhook signature verifier (PG-93).
 *
 * Mirrors py-service `app/conversion/cache_invalidator.py::_sign`:
 *   - X-Pandora-Timestamp: ISO-8601 UTC
 *   - X-Pandora-Nonce: random hex
 *   - X-Pandora-Signature: "sha256=" + hex of HMAC-SHA256(secret, timestamp.nonce.body)
 *
 * Replay protection: nonce is INSERTed into `lifecycle_invalidate_nonces`.
 * Duplicate INSERT → 200 short-circuit (publisher already considers the
 * invalidate delivered; reporting back 4xx would have it retry forever).
 */
class VerifyLifecycleInvalidateSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.pandora_lifecycle_invalidate.webhook_secret');
        if ($secret === '') {
            Log::error('[LifecycleInvalidate] missing PY_SERVICE_LIFECYCLE_INVALIDATE_SECRET');

            return response()->json(['error' => 'webhook secret not configured'], 500);
        }

        $timestamp = (string) $request->header('X-Pandora-Timestamp', '');
        $nonce = (string) $request->header('X-Pandora-Nonce', '');
        $signature = (string) $request->header('X-Pandora-Signature', '');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            return response()->json(['error' => 'missing signature headers'], 401);
        }

        $window = (int) config('services.pandora_lifecycle_invalidate.webhook_window_seconds', 300);
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

        try {
            LifecycleInvalidateNonce::create([
                'nonce' => $nonce,
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return response()->json(['status' => 'duplicate'], 200);
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
