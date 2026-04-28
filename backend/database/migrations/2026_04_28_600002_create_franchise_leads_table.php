<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * franchise_leads — 人工聯繫 inbox（**不**自動發訊給客戶）。
 *
 * UX sensitivity 重點（dodo CLAUDE.md / ADR-008）：
 *   - 客人很敏感，怕被當「業務目標」追殺
 *   - 系統只把訊號寫進 admin inbox，由業務**人工**接洽
 *   - 不發 email / 不發 LINE / 不發 SMS
 *
 * 欄位設計：
 *   - `pandora_user_uuid` 為主索引：未來跨 App lead aggregation 也以 uuid 為準
 *   - `user_id` legacy fallback：Phase F drop user_id 之前還是要支援
 *   - `source_app` 暫時只有 'doudou'，但欄位先加好給未來仙女學院 / 肌膚 App
 *   - `trigger_event` 與 ConversionEventPublisher 的 event_type 一致，方便分析
 *   - `trigger_payload` JSON：保留當時的 source / content_id 以便 BD 追問脈絡
 *   - `status` 6 種：new (剛進 inbox) / contacting / contacted / converted /
 *     dismissed (BD 評估後不接洽) / silenced (使用者主動 opt-out)
 *   - 不重複建：同 user 同 trigger 用 unique key，重複 fire 只 update updated_at
 *
 * 為什麼不用「最後一次 fire 時間」覆蓋 created_at：
 *   - created_at 留首次接觸時間，給 BD 看「多久前該 lead 出現」
 *   - updated_at 自然反映最新活動
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('franchise_leads', function (Blueprint $table) {
            $table->id();
            $table->char('pandora_user_uuid', 36)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('source_app', 32)->default('doudou');
            $table->string('trigger_event', 64);
            $table->json('trigger_payload')->nullable();
            $table->enum('status', ['new', 'contacting', 'contacted', 'converted', 'dismissed', 'silenced'])
                ->default('new')
                ->index();
            $table->string('assigned_to', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamps();

            // 同 user 同 trigger 不重複建。重複 fire 走 update path。
            $table->unique(['pandora_user_uuid', 'trigger_event'], 'franchise_leads_uuid_trigger_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('franchise_leads');
    }
};
