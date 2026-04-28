<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E — Idempotency / audit log for inbound IAP server-to-server events.
 *
 * Apple ASN v2 includes a `notificationUUID`; Google RTDN includes a Pub/Sub
 * `messageId`. We persist both before processing so that a delivery retry
 * lands on the unique constraint and we can short-circuit. raw_payload kept
 * for forensic replay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iap_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->enum('provider', ['apple', 'google']);
            $table->string('event_id', 128); // notificationUUID / messageId
            $table->string('event_type', 64)->nullable();
            $table->string('original_transaction_id', 128)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'iap_webhook_events_unique');
            $table->index('original_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iap_webhook_events');
    }
};
