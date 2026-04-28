<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase C 鋪軌驗證 — 18 個 reference tables 都有 nullable `pandora_user_uuid`。
 *
 * Strangler fig 策略的硬性 invariant：
 *   - 既有 `user_id` 欄位保留（22 services 還在用，不能直接 drop）
 *   - 同步 nullable `pandora_user_uuid` + INDEX（漸進遷移用）
 *   - referrals 因為是雙向關係，兩欄都要鋪：referrer / referee
 *
 * @see ADR-007 §2.3
 * @see database/migrations/..._add_pandora_user_uuid_to_reference_tables.php
 */
class ReferenceTablesDualColumnTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_all_reference_tables_have_dual_column_rails(): void
    {
        $missing = [];
        foreach (self::SINGLE_USER_COLUMN_TABLES as $table) {
            if (! Schema::hasColumn($table, 'user_id')) {
                $missing[] = "{$table}.user_id";
            }
            if (! Schema::hasColumn($table, 'pandora_user_uuid')) {
                $missing[] = "{$table}.pandora_user_uuid";
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Phase C 鋪軌期所有 reference tables 必須同時有 user_id（legacy 22 services 還在用）'
            .' 與 pandora_user_uuid（漸進遷移目的地）。缺少欄位：'.implode(', ', $missing),
        );
    }

    public function test_referrals_has_dual_column_rails_on_both_sides(): void
    {
        foreach (['referrer_id', 'referee_id', 'pandora_referrer_uuid', 'pandora_referee_uuid'] as $col) {
            $this->assertTrue(
                Schema::hasColumn('referrals', $col),
                "referrals 應該有欄位 `{$col}`（dual-column rails 兩邊都要）",
            );
        }
    }

    public function test_pandora_user_uuid_is_nullable_so_legacy_writes_dont_break(): void
    {
        $offending = [];

        $allTables = array_merge(
            self::SINGLE_USER_COLUMN_TABLES,
            ['referrals'] // 因為 referrals 不是 user_id 命名規則，特別處理
        );

        foreach ($allTables as $table) {
            $columns = Schema::getConnection()->getSchemaBuilder()->getColumns($table);

            $uuidCols = $table === 'referrals'
                ? ['pandora_referrer_uuid', 'pandora_referee_uuid']
                : ['pandora_user_uuid'];

            foreach ($uuidCols as $col) {
                $meta = collect($columns)->firstWhere('name', $col);
                if ($meta === null) {
                    $offending[] = "{$table}.{$col} (missing)";

                    continue;
                }
                if (! ($meta['nullable'] ?? false)) {
                    $offending[] = "{$table}.{$col} (NOT NULL)";
                }
            }
        }

        $this->assertSame(
            [],
            $offending,
            'pandora_user_uuid 系列欄位必須 nullable — 否則 legacy services 寫不下去：'.implode(', ', $offending),
        );
    }
}
