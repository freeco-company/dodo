<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-cross-metric-insight-v1 PR #1 — InsightEngine schema.
 *
 * insights:
 *   每條偵測到的 cross-metric pattern 寫一筆。Idempotent via idempotency_key
 *   (user_id:insight_key:YYYY-WW)，避免一週內同 user/rule 重複 fire。
 *
 * insight_rule_runs:
 *   每天每 rule 對每 user 跑一次的紀錄 → 用於 debug「為什麼今天沒 fire？」
 *   90 天 cleanup 排程 (insights:cleanup) 避免表爆。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insights', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('insight_key', 64)->index();
            $t->string('idempotency_key', 128)->unique();
            $t->json('detection_payload');
            $t->string('narrative_headline', 200);
            $t->text('narrative_body')->nullable();
            $t->json('action_suggestion');
            $t->string('source', 24)->default('rule_engine');
            $t->timestamp('fired_at');
            $t->timestamp('read_at')->nullable();
            $t->timestamp('pushed_at')->nullable();
            $t->timestamp('dismissed_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'fired_at']);
            $t->index(['user_id', 'read_at']);
        });

        Schema::create('insight_rule_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('rule_key', 64);
            $t->date('eval_date');
            $t->boolean('triggered')->default(false);
            $t->json('eval_context')->nullable();
            $t->timestamps();

            $t->unique(['user_id', 'rule_key', 'eval_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insight_rule_runs');
        Schema::dropIfExists('insights');
    }
};
