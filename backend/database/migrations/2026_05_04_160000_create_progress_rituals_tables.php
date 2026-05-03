<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-progress-ritual-v1 PR #1 — schema for ritual moment 系統.
 *
 * monthly_collages: 自動生成的月度集錦（4-9 張 progress photos + stats + 朵朵手寫信）
 * ritual_events: 任意 ritual 觸發記錄（slider / collage / outfit unlock / streak / season）
 * share_card_renders: 圖卡 PNG 產出 cache（同 source 不重 render）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_collages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('month_start');
            $t->json('snapshot_ids');
            $t->json('stats_payload');
            $t->text('narrative_letter');
            $t->string('image_path', 512)->nullable();
            $t->unsignedInteger('shared_count')->default(0);
            $t->timestamps();

            $t->unique(['user_id', 'month_start']);
        });

        Schema::create('ritual_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('ritual_key', 64)->index();
            $t->string('idempotency_key', 128)->unique();
            $t->json('payload');
            $t->timestamp('triggered_at');
            $t->timestamp('seen_at')->nullable();
            $t->timestamp('shared_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'seen_at']);
        });

        Schema::create('share_card_renders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('source_type', 32);
            $t->unsignedBigInteger('source_id');
            $t->string('image_path', 512);
            $t->string('checksum', 64)->index();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_card_renders');
        Schema::dropIfExists('ritual_events');
        Schema::dropIfExists('monthly_collages');
    }
};
