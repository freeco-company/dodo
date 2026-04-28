<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 朵朵端 gamification webhook 收件 idempotency 表 — ADR-009 §2.2 / Phase B.2.
 * 同 identity_webhook_nonces 設計，獨立一張避免兩條鏈路互相影響。
 *
 * `event_id` 由 py-service 產出，格式：`{event_type}.{ledger_id}` 或 fallback
 * `{event_type}.{uuid4}`。string(128) 容納兩種。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gamification_webhook_nonces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('event_id', 128)->unique();
            $table->string('event_type', 64);
            $table->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gamification_webhook_nonces');
    }
};
