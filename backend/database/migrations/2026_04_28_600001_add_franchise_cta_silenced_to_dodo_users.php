<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UX sensitivity follow-up — 客戶可主動 opt-out 永久不看 franchise CTA。
 *
 * 為什麼放 dodo_users 而不是 users：
 *   - dodo_users 是朵朵業務狀態的家（ADR-007 §2.3 准許「該 App 必要欄位」）
 *   - 「使用者偏好不看 CTA」是朵朵自己的 UX 訊號，不是身份 PII
 *   - 不需要寫進 platform / 不需要跨 App 同步（仙女學院、肌膚 App 各自獨立）
 *
 * 命名 `franchise_cta_silenced` 不用 `dismissed`：
 *   - dismiss = 暫時關閉（cooldown），由 frontend localStorage 處理
 *   - silenced = 永久靜音，跨裝置生效，server-side authoritative
 *   - 兩個機制並存：localStorage 是輕量 UX，server flag 是強烈意願
 *
 * silenced_at 同時加：給 admin / BI 看「使用者多久前主動 opt-out」，
 * 也可作為「冷卻期過後是否該主動再露出」的政策旋鈕（目前一律不再露出）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dodo_users', function (Blueprint $table) {
            $table->boolean('franchise_cta_silenced')
                ->default(false)
                ->after('push_enabled');
            $table->timestamp('franchise_cta_silenced_at')
                ->nullable()
                ->after('franchise_cta_silenced');
        });
    }

    public function down(): void
    {
        Schema::table('dodo_users', function (Blueprint $table) {
            $table->dropColumn(['franchise_cta_silenced', 'franchise_cta_silenced_at']);
        });
    }
};
