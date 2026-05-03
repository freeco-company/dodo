<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-pikmin-walk-v1 — Pikmin Bloom 風格計步深度遊戲化。
 *
 * 兩張表：
 *  1. mini_dodo_collections — 每隻 mini-dodo 召喚紀錄
 *     一隻 mini-dodo 由 (user, date, color, source_kind) 唯一決定，
 *     一天同色從同來源（ex. meal）只算一隻；多來源（meal+steps milestone）可疊。
 *  2. daily_walk_sessions — 每天一筆，記錄 step total / phase / mini-dodo 召喚摘要
 *     phase 跟著 step total 升級（seed/sprout/bloom/fruit）；峰值 phase 留作獎勵觸發判定。
 *
 * 為什麼不直接用既有 health_metrics 計算 phase：phase 含「最後解鎖」狀態（idempotent
 * gamification publish 用），且加上 mini_dodos_summoned_json 摘要供 home widget 一次撈，
 * 避免每次都跑 aggregator。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mini_dodo_collections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('collected_on'); // local-day
            $t->enum('color', ['red', 'green', 'blue', 'yellow', 'purple']);
            // source_kind: meal=從 macros 推；steps=步數階段達成；fasting=斷食完成；photo=進度照
            $t->enum('source_kind', ['meal', 'steps', 'fasting', 'photo']);
            $t->unsignedBigInteger('source_ref_id')->nullable(); // meal_id / fasting_session_id / progress_snapshot_id
            $t->string('source_detail', 64)->nullable(); // 'protein_high' / 'phase_bloom' 等
            $t->timestamp('collected_at');
            $t->timestamps();

            $t->unique(['user_id', 'collected_on', 'color', 'source_kind'], 'mini_dodo_uniq_per_day');
            $t->index(['user_id', 'collected_on'], 'mini_dodo_user_date');
        });

        Schema::create('daily_walk_sessions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('walk_date');
            $t->unsignedInteger('total_steps')->default(0);
            // phase: seed (0-2k) / sprout (2-5k) / bloom (5-8k) / fruit (8k+)
            $t->enum('peak_phase', ['seed', 'sprout', 'bloom', 'fruit'])->default('seed');
            $t->json('mini_dodos_summoned_json')->nullable(); // [{color,source_kind,collected_at}]
            $t->boolean('goal_published')->default(false); // meal.steps_goal_achieved fired?
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();

            $t->unique(['user_id', 'walk_date'], 'daily_walk_user_date_uniq');
            $t->index(['user_id', 'walk_date'], 'daily_walk_user_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_walk_sessions');
        Schema::dropIfExists('mini_dodo_collections');
    }
};
