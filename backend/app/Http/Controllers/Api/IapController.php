<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Iap\IapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase E — RN App POSTs receipt here right after a successful Apple/Google
 * purchase. We verify, drive the state machine, and return the new
 * subscription state. If the platform IAP keys are not configured the
 * verifier throws IapNotConfiguredException → 503 (handled globally).
 */
class IapController extends Controller
{
    public function __construct(private readonly IapService $iap) {}

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', 'in:apple,google'],
            'receipt' => ['required_without:purchase_token', 'string'],
            'purchase_token' => ['required_without:receipt', 'string'],
            'product_id' => ['nullable', 'string', 'max:128'],
            'package_name' => ['nullable', 'string', 'max:128'],
        ]);

        $token = $data['receipt'] ?? $data['purchase_token'];
        $sub = $this->iap->verifyAndApply(
            $request->user(),
            $data['platform'],
            $token,
            [
                'product_id' => $data['product_id'] ?? '',
                'package_name' => $data['package_name'] ?? null,
            ],
        );

        return response()->json([
            'subscription_id' => $sub->id,
            'state' => $sub->state,
            'plan' => $sub->plan,
            'product_id' => $sub->product_id,
            'current_period_end' => $sub->current_period_end?->toIso8601String(),
        ]);
    }
}
