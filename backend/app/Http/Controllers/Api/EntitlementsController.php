<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/entitlements — current user's entitlement snapshot.
 * Wraps EntitlementsService (already a port from ai-game). The same data is
 * also embedded in /api/bootstrap, but the frontend re-fetches after IAP /
 * island-visit consumption.
 */
class EntitlementsController extends Controller
{
    public function __construct(private readonly EntitlementsService $service) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->service->get($request->user()));
    }
}
