<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppConfigService;
use App\Services\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/bootstrap — single-call hydration for client.
 * Returns runtime app_config (paywall, disclaimer, push templates), plus
 * the current user's entitlements snapshot if authenticated.
 */
class BootstrapController extends Controller
{
    public function __construct(
        private readonly AppConfigService $config,
        private readonly EntitlementsService $entitlements,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        // Sanctum-optional: try to resolve user from bearer token, but never error.
        $user = $request->user('sanctum') ?? $request->user();
        return response()->json([
            'app_config' => [
                'paywall' => $this->config->get('paywall'),
                'disclaimer' => $this->config->get('disclaimer'),
                'push_templates' => $this->config->get('push_templates'),
                'tier_limits' => $this->config->get('tier_limits'),
            ],
            'entitlements' => $user ? $this->entitlements->get($user) : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'membership_tier' => $user->membership_tier,
                'subscription_type' => $user->subscription_type,
            ] : null,
        ]);
    }
}
