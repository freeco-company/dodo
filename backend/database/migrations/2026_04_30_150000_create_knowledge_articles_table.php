<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * knowledge_articles — 營養知識庫（給 App 端「每日一則 / 主題瀏覽」+ 加盟者後台「推給客戶」）。
 *
 * 來源：
 *   - 2026-04-30 種子 160 張營養師群組截圖（storage/seed/nutrition_kb/raw/）
 *   - Phase 5 OCR + 人工 review 後 seed 進來
 *   - 加盟者 / admin 之後也可手動 publish
 *
 * 設計重點：
 *   - 用 slug 做 URL-safe identifier，不用 auto-increment ID 對外曝
 *   - category 用 enum 限制，避免 free-form 散
 *   - tags JSON：跨 category 標記（減脂期 / 維持期 / 產品搭配 / 常見 Q&A 等）
 *   - audience JSON：['retail', 'franchisee']，控制誰看得到
 *   - reading_time_seconds：給前端進度 / 「2 分鐘看完」UI
 *   - source_image：raw/ 內檔名，追溯來源
 *   - dodo_voice_body：朵朵語氣改寫版（NPC 講話），原 source 是營養師專業語氣
 *   - published_at 為 null = draft；定時任務 pick published 來推每日一則
 *
 * 為什麼不抽到獨立 micro-service：
 *   - 知識內容跟 user / lifecycle 緊耦合（推播 / 加盟者標記客戶）
 *   - 內容量級小（< 1000 articles 1 年內），單表夠用
 *   - 跨 App 共享是 Phase 6+ 才考慮，到時再抽 KB micro-service
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_articles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title', 200);

            $table->enum('category', [
                'protein',          // 蛋白質
                'carb',             // 碳水
                'fiber',            // 纖維
                'fat',              // 油脂
                'water',            // 水分
                'micronutrient',    // 微量元素
                'product_match',    // 產品搭配
                'meal_timing',      // 餐次安排
                'cutting',          // 減脂期
                'maintenance',      // 維持期
                'qna',              // 常見 Q&A
                'myth_busting',     // 謬誤澄清
                'lifestyle',        // 生活作息
                'other',
            ])->default('other')->index();

            $table->json('tags')->nullable();
            $table->json('audience')->nullable();

            $table->text('summary')->nullable();
            $table->longText('body');
            $table->longText('dodo_voice_body')->nullable();

            $table->unsignedSmallInteger('reading_time_seconds')->nullable();

            $table->string('source_image', 200)->nullable()->index();
            $table->string('source_attribution', 200)->nullable();

            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('saved_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['category', 'published_at']);
        });

        Schema::create('knowledge_article_user_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained('knowledge_articles')->cascadeOnDelete();
            $table->uuid('pandora_user_uuid')->index();
            $table->enum('action', ['viewed', 'saved', 'shared'])->index();
            $table->timestamp('acted_at')->useCurrent();
            $table->index(['pandora_user_uuid', 'article_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_article_user_marks');
        Schema::dropIfExists('knowledge_articles');
    }
};
