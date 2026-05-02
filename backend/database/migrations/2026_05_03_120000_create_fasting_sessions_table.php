<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-fasting-timer Phase 1 — fasting_sessions table.
 *
 * One row per fasting attempt. `ended_at NULL` means active (only one active
 * row per user enforced by partial unique index at app level via service guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fasting_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('mode', 16)->comment('16:8 | 14:10 | 18:6 | 20:4 | 5:2 | custom');
            $table->unsignedSmallInteger('target_duration_minutes')->comment('e.g. 16:8 = 960');
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->boolean('completed')->default(false)->comment('true if ended_at - started_at >= target');
            $table->string('source_app', 16)->default('dodo');
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fasting_sessions');
    }
};
