<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Iap\IapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

    /**
     * Apple §3.1.1 — Restore Purchases. Client passes back the array of
     * receipts the user previously bought (Capacitor IAP plugin returns this
     * on restore). We re-verify each + apply to the user's subscription
     * state, returning the latest active sub.
     *
     * No-op safe: if user has no purchases, returns 200 with restored: 0.
     */
    public function restore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['nullable', 'in:apple,google'],
            'receipts' => ['required', 'array', 'max:20'],
            'receipts.*.product_id' => ['nullable', 'string', 'max:128'],
            'receipts.*.receipt' => ['nullable', 'string'],
            'receipts.*.purchase_token' => ['nullable', 'string'],
        ]);

        $platform = $data['platform'] ?? 'apple';
        $applied = 0;
        $latest = null;

        foreach ($data['receipts'] as $r) {
            $token = $r['receipt'] ?? $r['purchase_token'] ?? null;
            if (! is_string($token) || $token === '') {
                continue;
            }
            try {
                $sub = $this->iap->verifyAndApply(
                    $request->user(),
                    $platform,
                    $token,
                    ['product_id' => $r['product_id'] ?? ''],
                );
                $applied++;
                $latestEnd = $latest?->current_period_end;
                $thisEnd = $sub->current_period_end;
                if ($latest === null || ($thisEnd !== null && $latestEnd !== null && $thisEnd->gt($latestEnd))) {
                    $latest = $sub;
                }
            } catch (\Throwable $e) {
                // Skip individual receipt failures so partial restores still
                // return what we could verify. Log for ops triage.
                Log::info('[IapRestore] receipt skipped', [
                    'reason' => $e->getMessage(),
                    'product_id' => $r['product_id'] ?? null,
                ]);
            }
        }

        return response()->json([
            'restored' => $applied,
            'subscription_id' => $latest?->id,
            'state' => $latest?->state,
            'plan' => $latest?->plan,
            'product_id' => $latest?->product_id,
            'current_period_end' => $latest?->current_period_end?->toIso8601String(),
        ]);
    }
}
