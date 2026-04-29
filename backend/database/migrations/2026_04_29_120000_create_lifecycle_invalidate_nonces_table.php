<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replay-protection nonce store for the lifecycle cache-invalidate webhook (PG-93).
 *
 * Stores only the nonce header value (random hex from py-service) because each
 * invalidate is independent — there is no `event_id` semantics on this channel.
 * Periodically prune rows older than the verifier window (e.g. > 1h) via a
 * scheduled job; not in scope for this ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lifecycle_invalidate_nonces', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('nonce', 64)->unique();
            $table->timestamp('received_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_invalidate_nonces');
    }
};
