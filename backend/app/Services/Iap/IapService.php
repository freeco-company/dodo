<?php

namespace App\Services\Iap;

use App\Models\IapWebhookEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Subscription\SubscriptionStateMachine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase E — orchestrator for IAP receipt verification + state-machine writes.
 *
 * Public surface:
 *   - verifyAndApply($user, 'apple', $receipt)            — RN initial purchase
 *   - verifyAndApply($user, 'google', $token, $productId)
 *   - applyServerNotification($provider, $payload)        — webhook entrypoint
 *
 * Mapping product_id → plan is contained here so verifiers stay dumb.
 */
class IapService
{
    public function __construct(
        private readonly AppleReceiptVerifier $apple,
        private readonly GoogleReceiptVerifier $google,
        private readonly SubscriptionStateMachine $stateMachine,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public function verifyAndApply(User $user, string $provider, string $receiptOrToken, array $extra = []): Subscription
    {
        $result = match ($provider) {
            'apple' => $this->apple->verify($receiptOrToken),
            'google' => $this->google->verify(
                (string) ($extra['package_name'] ?? config('services.iap.google.package_name', 'com.dodo.app')),
                (string) ($extra['product_id'] ?? ''),
                $receiptOrToken,
            ),
            default => throw new InvalidArgumentException("Unsupported IAP provider: {$provider}"),
        };

        return $this->applyVerificationResult($user, $result);
    }

    /**
     * Idempotently record an Apple ASN v2 / Google RTDN event, then drive the
     * state machine. Caller is responsible for signature verification before
     * handing the payload here.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyServerNotification(string $provider, string $eventId, ?string $eventType, array $payload): ?Subscription
    {
        // Idempotency: insertOrIgnore on (provider, event_id).
        $event = IapWebhookEvent::query()
            ->where('provider', $provider)
            ->where('event_id', $eventId)
            ->first();

        if ($event && $event->processed_at) {
            return null; // already processed, drop the duplicate
        }

        $event = $event ?? IapWebhookEvent::create([
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'original_transaction_id' => $payload['original_transaction_id'] ?? null,
            'raw_payload' => $payload,
        ]);

        $sub = null;
        $otid = (string) ($payload['original_transaction_id'] ?? '');
        if ($otid !== '') {
            $sub = Subscription::query()
                ->where('provider', $provider)
                ->where('provider_subscription_id', $otid)
                ->first();
        }

        if ($sub) {
            $sub = $this->dispatchEvent($sub, $eventType, $payload);
        }

        $event->processed_at = Carbon::now();
        $event->save();

        return $sub;
    }

    private function applyVerificationResult(User $user, IapVerificationResult $r): Subscription
    {
        return DB::transaction(function () use ($user, $r) {
            $plan = $this->productIdToPlan($r->productId);
            $sub = $this->stateMachine->findOrInitialise(
                $user,
                $r->provider,
                $r->originalTransactionId,
                $r->productId,
                $plan,
            );

            return $this->stateMachine->activate(
                $sub,
                $r->purchasedAt,
                $r->expiresAt,
                $r->rawPayload,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchEvent(Subscription $sub, ?string $eventType, array $payload): Subscription
    {
        return match ($eventType) {
            // Apple ASN v2 notificationType / Google RTDN notificationType — common cases:
            'DID_RENEW', 'SUBSCRIPTION_RENEWED', 'SUBSCRIPTION_RECOVERED' => $this->stateMachine->activate(
                $sub,
                $this->payloadCarbon($payload, 'period_start') ?? Carbon::now(),
                $this->payloadCarbon($payload, 'period_end') ?? Carbon::now()->addMonth(),
                $payload,
            ),
            'DID_FAIL_TO_RENEW', 'GRACE_PERIOD_STARTED', 'SUBSCRIPTION_IN_GRACE_PERIOD' => $this->stateMachine->moveToGrace(
                $sub,
                $this->payloadCarbon($payload, 'grace_until') ?? Carbon::now()->addDays(16),
            ),
            'EXPIRED', 'SUBSCRIPTION_EXPIRED' => $this->stateMachine->expire($sub),
            'REFUND', 'REVOKE', 'SUBSCRIPTION_REVOKED' => $this->stateMachine->refund($sub),
            default => $sub, // unknown event → audit row only, no transition
        };
    }

    private function productIdToPlan(string $productId): ?string
    {
        if ($productId === '') {
            return null;
        }
        if (str_contains($productId, 'yearly') || str_contains($productId, 'year')) {
            return 'app_yearly';
        }
        if (str_contains($productId, 'monthly') || str_contains($productId, 'month')) {
            return 'app_monthly';
        }

        return null;
    }

    /** @param  array<string, mixed>  $payload */
    private function payloadCarbon(array $payload, string $key): ?Carbon
    {
        $v = $payload[$key] ?? null;
        if (! $v) {
            return null;
        }
        try {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                return Carbon::createFromTimestamp((int) $v);
            }

            return Carbon::parse((string) $v);
        } catch (\Throwable) {
            return null;
        }
    }
}
