<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E — Subscription state machine table.
 *
 * Why a dedicated table (not just User columns)?
 *   - One user may have multiple historical subs (renewed → cancelled → resubscribed).
 *   - We need a per-event audit trail (Apple ASN v2, Google RTDN, ECPay notify).
 *   - User.subscription_type / subscription_expires_at_iso continues to hold the
 *     **current effective** state (mirrored by SubscriptionObserver) so the rest
 *     of the codebase doesn't have to change. Subscription is the source of
 *     truth + history.
 *
 * dual-column (user_id + pandora_user_uuid) per ADR-007 Phase D Wave 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('pandora_user_uuid', 36)->nullable()->index();

            // Provider taxonomy. 'mock' covers TierService::mockSubscribe legacy path.
            $table->enum('provider', ['apple', 'google', 'ecpay', 'mock']);
            // The provider's stable subscription identifier:
            //   - apple: original_transaction_id
            //   - google: purchaseToken (or linked subscription id)
            //   - ecpay: MerchantTradeNo of the first auth
            $table->string('provider_subscription_id', 128)->nullable();
            // The product / plan SKU as registered with the provider.
            $table->string('product_id', 128)->nullable();
            // Internal plan mapping (matches User.subscription_type semantics).
            $table->enum('plan', ['app_monthly', 'app_yearly'])->nullable();

            // State machine. See SubscriptionStateMachine for transitions.
            $table->enum('state', ['trial', 'active', 'grace', 'expired', 'refunded'])
                ->default('trial');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('grace_until')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('last_event_at')->nullable();

            // Last raw provider payload — useful when debugging webhook regressions.
            $table->json('raw_payload')->nullable();

            $table->timestamps();

            // Idempotency: one (provider, provider_subscription_id) row max.
            // Nullable provider_subscription_id is allowed (mock plans).
            $table->unique(['provider', 'provider_subscription_id'], 'subscriptions_provider_id_unique');
            $table->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
