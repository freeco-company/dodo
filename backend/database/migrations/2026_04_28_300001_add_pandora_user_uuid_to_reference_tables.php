<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C (ADR-007 §2.3) — 朵朵 identity wire 的「鋪軌」階段。
 *
 * 為什麼這支 migration 存在：
 *
 *   - Phase A 的所有業務表都用 `user_id` foreign key 接到 legacy `users` 表
 *     （sanctum 簽出來的 auto-increment id）。
 *   - 集團統一身份是 `pandora_user_uuid` (UUID v7, 由 Pandora Core 簽發)。
 *   - 直接 swap 會破 22 個 services / 56 個 endpoints / 106 個 tests，
 *     而且前端目前還沒接 platform JWT，會直接 break auth flow。
 *
 * 鋪軌策略（dual-column / strangler fig）：
 *
 *   1. 各 reference table **同時**保留 `user_id` (legacy) + 新增 nullable
 *      `pandora_user_uuid` (新)。
 *   2. service 層往後一個一個 PR 遷移，漸進改用 uuid 查詢。
 *   3. 全部 service 改完 + 前端切 platform JWT 之後（Phase F），最後一輪
 *      drop `user_id` 欄位 + drop foreign key。
 *
 * 為什麼是 nullable：
 *   - 既有 row 沒有 uuid（legacy User 還沒 backfill 進 DodoUser mirror）
 *   - backfill 由 DodoUserSyncService 處理（webhook 來時雙向 mirror）
 *   - nullable + index = 可以 query 也可以漸進補資料
 *
 * 為什麼不加 foreign key 到 dodo_users：
 *   - dodo_users 是 minimal mirror，platform 是 SoT，FK 會強迫先有 mirror 才能寫業務 row
 *   - Phase F drop user_id 時再考慮加 FK，現在保持 loose coupling
 *
 * @see ADR-007 §2.3 — 朵朵作為消費端 App，uuid 是身份單一辨識
 * @see HANDOFF.md - Phase C identity wire 「lay the rails」原則
 */
return new class extends Migration
{
    /**
     * 17 張表只有單一 user_id 欄位 — 一刀切的格式。
     */
    private const SINGLE_USER_COLUMN_TABLES = [
        'daily_logs',
        'meals',
        'conversations',
        'user_summaries',
        'weekly_reports',
        'achievements',
        'food_discoveries',
        'usage_logs',
        'card_plays',
        'card_event_offers',
        'daily_quests',
        'store_visits',
        'journey_advances',
        'analytics_events',
        'push_tokens',
        'client_errors',
        'rating_prompt_events',
        'paywall_events',
    ];

    public function up(): void
    {
        foreach (self::SINGLE_USER_COLUMN_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->char('pandora_user_uuid', 36)
                    ->nullable()
                    ->after('user_id')
                    ->comment('ADR-007 §2.3 dual-column rails — Phase F drop user_id');

                $table->index('pandora_user_uuid', "{$tableName}_pandora_user_uuid_idx");
            });
        }

        // referrals 是雙欄表（referrer_id / referee_id），各加一支 uuid 欄位。
        Schema::table('referrals', function (Blueprint $table) {
            $table->char('pandora_referrer_uuid', 36)
                ->nullable()
                ->after('referrer_id')
                ->comment('ADR-007 §2.3 dual-column rails — referrer side');

            $table->char('pandora_referee_uuid', 36)
                ->nullable()
                ->after('referee_id')
                ->comment('ADR-007 §2.3 dual-column rails — referee side');

            $table->index('pandora_referrer_uuid', 'referrals_pandora_referrer_uuid_idx');
            $table->index('pandora_referee_uuid', 'referrals_pandora_referee_uuid_idx');
        });
    }

    public function down(): void
    {
        foreach (self::SINGLE_USER_COLUMN_TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex("{$tableName}_pandora_user_uuid_idx");
                $table->dropColumn('pandora_user_uuid');
            });
        }

        Schema::table('referrals', function (Blueprint $table) {
            $table->dropIndex('referrals_pandora_referrer_uuid_idx');
            $table->dropIndex('referrals_pandora_referee_uuid_idx');
            $table->dropColumn(['pandora_referrer_uuid', 'pandora_referee_uuid']);
        });
    }
};
