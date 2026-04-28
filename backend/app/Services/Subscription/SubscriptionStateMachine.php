<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Phase E — Subscription state machine.
 *
 * States:                      Triggers:
 *   trial                      seed (free 7-day trial; not actually used by IAP path)
 *   trial → active             paid receipt verified
 *   active → grace             renewal failed but provider still retrying
 *   grace → active             renewal recovered (BILLING_RECOVERY for Google;
 *                              GRACE_PERIOD_EXPIRED.refunded for Apple)
 *   grace → expired            grace_until passed without recovery
 *   active → expired           cron sweep: current_period_end past + no renewal
 *   active|grace → refunded    REFUND notification
 *
 * Transitions are deliberately one-way (no expired→active jump) — a new active
 * needs a fresh row (because Apple/Google reuse original_transaction_id but
 * we want a new history record per subscription cycle? In Apple's model the
 * same original_transaction_id covers the lifetime of a Subscription Group
 * tier, so we keep one row + just bump current_period_end. Refunded is the
 * terminal "this row is closed" marker — re-subscribe creates a new row.).
 *
 * Why not Workflow / Spatie state machine?
 *   - 5 states + 6 transitions doesn't justify a dependency.
 *   - The provider events themselves drive transitions; we just enforce the
 *     legal moves here.
 *
 * After every transition we mirror the effective tier/expiry to the legacy
 * User columns so EntitlementsService / TierService keep working unchanged.
 * (See SubscriptionObserver.)
 */
class SubscriptionStateMachine
{
    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'trial' => ['active', 'expired'],
        'active' => ['grace', 'expired', 'refunded'],
        'grace' => ['active', 'expired', 'refunded'],
        'expired' => [],   // terminal
        'refunded' => [],  // terminal
    ];

    /**
     * Activate (or reactivate, e.g. trial→active or grace→active) a subscription.
     * Used for first-time IAP verify and successful renewal callbacks.
     *
     * @param  array<string, mixed>  $payload
     */
    public function activate(
        Subscription $sub,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $payload = []
    ): Subscription {
        $this->guardTransition($sub->state, 'active');

        return DB::transaction(function () use ($sub, $periodStart, $periodEnd, $payload) {
            $sub->state = 'active';
            $sub->current_period_start = $periodStart;
            $sub->current_period_end = $periodEnd;
            $sub->grace_until = null;
            $sub->started_at = $sub->started_at ?? $periodStart;
            $sub->last_event_at = Carbon::now();
            if (! empty($payload)) {
                $sub->raw_payload = $payload;
            }
            $sub->save();

            return $sub;
        });
    }

    /** Provider says renewal failed; allow grace window before expiry. */
    public function moveToGrace(Subscription $sub, Carbon $graceUntil): Subscription
    {
        $this->guardTransition($sub->state, 'grace');

        $sub->state = 'grace';
        $sub->grace_until = $graceUntil;
        $sub->last_event_at = Carbon::now();
        $sub->save();

        return $sub;
    }

    public function expire(Subscription $sub): Subscription
    {
        $this->guardTransition($sub->state, 'expired');

        $sub->state = 'expired';
        $sub->grace_until = null;
        $sub->last_event_at = Carbon::now();
        $sub->save();

        return $sub;
    }

    public function refund(Subscription $sub): Subscription
    {
        $this->guardTransition($sub->state, 'refunded');

        $sub->state = 'refunded';
        $sub->refunded_at = Carbon::now();
        $sub->last_event_at = Carbon::now();
        $sub->save();

        return $sub;
    }

    /**
     * Find or build the canonical Subscription row for a (provider, provider_subscription_id)
     * tuple. Used by IapService and EcpayClient to attach incoming events.
     */
    public function findOrInitialise(
        User $user,
        string $provider,
        string $providerSubscriptionId,
        ?string $productId,
        ?string $plan
    ): Subscription {
        $sub = Subscription::query()
            ->where('provider', $provider)
            ->where('provider_subscription_id', $providerSubscriptionId)
            ->first();

        if (! $sub) {
            $sub = new Subscription([
                'user_id' => $user->id,
                'pandora_user_uuid' => $user->pandora_user_uuid,
                'provider' => $provider,
                'provider_subscription_id' => $providerSubscriptionId,
                'product_id' => $productId,
                'plan' => $plan,
                'state' => 'trial',
            ]);
        } else {
            // Update plan/product if provider changed it (e.g. user upgraded monthly→yearly)
            if ($productId && $sub->product_id !== $productId) {
                $sub->product_id = $productId;
            }
            if ($plan && $sub->plan !== $plan) {
                $sub->plan = $plan;
            }
        }

        return $sub;
    }

    private function guardTransition(string $from, string $to): void
    {
        if ($from === $to) {
            // Idempotent re-apply (e.g. activate on already-active during renewal). Allowed.
            return;
        }
        $allowed = self::ALLOWED_TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException(
                "Illegal subscription transition: {$from} → {$to}"
            );
        }
    }
}
