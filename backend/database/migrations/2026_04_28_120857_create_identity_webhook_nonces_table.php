<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 朵朵端 webhook nonce — 防 replay。設計與母艦
 * (pandora-js-store) 完全一致，方便 ops 思維對齊。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_webhook_nonces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('event_id', 36)->unique();
            $table->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_webhook_nonces');
    }
};
