<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Inbound webhooks from 婕樂纖 (pandora.js-store) e-commerce.
 *
 * Signature verification is enforced upstream by
 * {@see \App\Http\Middleware\VerifyEcommerceWebhookSignature}: HMAC-SHA256
 * over `{timestamp}.{body}` using `MEAL_ECOMMERCE_WEBHOOK_SECRET`. This
 * controller can assume the body is authentic and the timestamp is fresh.
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
