<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound webhooks from婕樂纖 (pandora.js-store) e-commerce.
 *
 * TODO: implement real signature verification (HMAC-SHA256 over raw body
 *       using a shared secret env MEAL_ECOMMERCE_WEBHOOK_SECRET). For now
 *       we just log + log + 200 so the producer can be wired without losing
 *       events. SECURITY: do NOT expose this in production until the
 *       signature check lands.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly TierService $tier) {}

    public function ecommerceOrder(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'order_id' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email'],
            'user_id' => ['nullable', 'integer'],
            'products' => ['nullable', 'array'],
        ]);

        Log::info('[webhook:ecommerce] received', $payload);

        // Best-effort apply (no-op if user not found — webhook may arrive
        // before the user signs up).
        $result = $this->tier->applyEcommerceOrder($payload);

        return response()->json([
            'ok' => true,
            'matched' => $result !== null,
            'result' => $result,
        ]);
    }
}
