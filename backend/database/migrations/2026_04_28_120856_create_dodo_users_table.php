<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (ADR-007 §2.3 / pandora-core-identity#11)：朵朵的 minimal mirror。
 *
 * 設計重點 — 朵朵作為**消費端 App**（非母艦），ADR-007 §2.3 強制：
 *
 *   ❌ 禁止欄位：email / phone / address / password_hash / oauth tokens / 任何 PII
 *   ✅ 准許欄位：uuid (PK) / display_name / avatar_url / subscription_tier
 *
 * 為什麼這麼嚴格：
 *   - 朵朵需要顯示「使用者頭像 + 暱稱 + 訂閱狀態」做遊戲體驗、權限判斷
 *   - 不需要碰 PII（不寄信、不打電話、不下訂單；那是母艦的事）
 *   - 防止 PII 散落多個 service：legal compliance、data breach blast radius、
 *     單一 source of truth (Pandora Core)
 *
 * 為什麼新表而不改 users：
 *   - 既有 users 表（Phase A）含大量 PII + 業務欄位（subscription / streak /
 *     pet 互動 / etc），refactor 成本太高且破壞 106 個現有 tests
 *   - 新表 = 乾淨；遷移路徑：未來逐步把 routes 從 users 改到 dodo_users
 *   - Phase A 的 users 表先當 legacy，Phase 5+ 才陸續退場
 *
 * 主鍵設計：直接用 UUID v7 當 PK（沒 auto-increment id），與 platform 一致。
 * 朵朵側永遠以 uuid 認人，避免 internal id 與 platform uuid 雙軌混淆。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dodo_users', function (Blueprint $table) {
            // platform 簽出來的 UUID v7。直接當 PK，沒有 auto-increment 障礙。
            $table->char('pandora_user_uuid', 36)->primary();

            // 顯示用，可能 null（platform 還沒填）
            $table->string('display_name', 100)->nullable();
            $table->string('avatar_url', 500)->nullable();

            // 訂閱層級。null = free / 'premium' / 其他 — 朵朵自己會擴充
            $table->string('subscription_tier', 32)->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dodo_users');
    }
};
