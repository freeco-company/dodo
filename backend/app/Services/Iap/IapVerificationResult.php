<?php

namespace App\Services\Iap;

use Carbon\Carbon;

/**
 * Provider-agnostic verification result. Both Apple verifyReceipt and Google
 * Play Billing API responses are mapped into this DTO so IapService doesn't
 * have to branch on provider after verification.
 */
final class IapVerificationResult
{
    /** @param  array<string, mixed>  $rawPayload */
    public function __construct(
        public readonly string $provider,            // 'apple' | 'google'
        public readonly string $originalTransactionId,
        public readonly string $productId,
        public readonly Carbon $purchasedAt,
        public readonly Carbon $expiresAt,
        public readonly bool $isSandbox,
        public readonly array $rawPayload,
    ) {}
}
