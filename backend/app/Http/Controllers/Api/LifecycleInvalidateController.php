<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Conversion\LifecycleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * py-service → 潘朵拉飲食 lifecycle cache invalidation receiver (PG-93).
 *
 * Signature verification + replay protection upstream by
 * {@see \App\Http\Middleware\VerifyLifecycleInvalidateSignature}, so this
 * controller can trust the request is authentic and unique.
 *
 * Unknown / malformed bodies still 200 (publisher considers any non-2xx a
 * failure and retries; for cache invalidation a missed call merely defers
 * freshness to TTL — never serve a hard error here).
 */
class LifecycleInvalidateController extends Controller
{
    public function __construct(private readonly LifecycleClient $lifecycle) {}

    public function handle(Request $request): JsonResponse
    {
        $uuid = (string) $request->json('pandora_user_uuid', '');
        if ($uuid === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'missing uuid'], 200);
        }
        $this->lifecycle->forget($uuid);

        return response()->json(['status' => 'ok'], 200);
    }
}
