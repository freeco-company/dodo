<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay-protection nonce table for the 母艦 (pandora-js-store) → 朵朵
 * franchisee status sync webhook (POST /api/internal/franchisee/webhook).
 *
 * Separate from identity_webhook_nonces / gamification_webhook_nonces so the
 * three event sources can't trample on each other's event_ids and so it's
 * obvious in DBA tooling which table belongs to which producer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('franchisee_webhook_nonces')) {
            Schema::create('franchisee_webhook_nonces', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('event_id', 36)->unique();
                $table->timestamp('received_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('franchisee_webhook_nonces');
    }
};
