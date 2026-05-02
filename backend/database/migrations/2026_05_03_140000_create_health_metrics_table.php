<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-healthkit-integration Phase 1 — health_metrics table.
 *
 * Receives metrics uploaded from HealthKit (iOS) / Health Connect (Android)
 * via POST /api/health/sync. Source-of-truth for type / unit:
 *
 *   type           |  unit  |  notes
 *   ──────────────────────────────────────────────────────────
 *   steps          |  count |  daily total (one row per day)
 *   active_kcal    |  kcal  |  daily total
 *   weight         |  kg    |  one per measurement
 *   sleep_minutes  |  min   |  one per night (paid tier)
 *   heart_rate     |  bpm   |  resting daily avg (paid tier)
 *   workout        |  count |  count of sessions per day
 *
 * Dedup: (user_id, type, recorded_at) is a logical unique — sync is
 * upsert-by-this-key. We keep raw_payload nullable for debugging /
 * retention pruning at 90 days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->comment('steps | active_kcal | weight | sleep_minutes | heart_rate | workout');
            $table->double('value');
            $table->string('unit', 16);
            $table->dateTimeTz('recorded_at')->comment('local-time of the metric (start of day for daily aggregates)');
            $table->string('source', 32)->default('healthkit')->comment('healthkit | health_connect | manual');
            $table->json('raw_payload')->nullable()->comment('original device payload for debugging — pruned after 90 days');
            $table->timestamps();

            $table->unique(['user_id', 'type', 'recorded_at'], 'health_metrics_dedup_uq');
            $table->index(['user_id', 'type', 'recorded_at'], 'health_metrics_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_metrics');
    }
};
