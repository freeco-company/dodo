<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADR-007 §2.3 enforcement — 朵朵 dodo_users 永遠不能存 PII。
 *
 * 此 test 跑在 CI，會在任何人偷偷加 email / phone / password / address /
 * oauth token 欄位時直接 fail，是保險 — 提醒開發者：朵朵不存 PII，要 PII
 * 透過 platform API 即時取。
 */
class DodoUserSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_COLUMNS = [
        'email',
        'email_canonical',
        'phone',
        'phone_canonical',
        'password',
        'password_hash',
        'address',
        'address_city',
        'address_district',
        'address_detail',
        'oauth_token',
        'access_token',
        'refresh_token',
        'google_id',
        'line_id',
        'apple_id',
        'real_name',
        'birthday',
    ];

    public function test_dodo_users_table_has_no_pii_columns(): void
    {
        $forbidden = [];
        foreach (self::FORBIDDEN_COLUMNS as $col) {
            if (Schema::hasColumn('dodo_users', $col)) {
                $forbidden[] = $col;
            }
        }

        $this->assertSame(
            [],
            $forbidden,
            'ADR-007 §2.3 violation: dodo_users 不能存 PII 欄位 ('.implode(', ', $forbidden).')。'
            .'PII 應該透過 platform `GET /api/v1/users/{uuid}` 即時取得，不能落地到朵朵 DB。',
        );
    }

    public function test_dodo_users_only_has_allowed_columns(): void
    {
        $allowed = [
            'pandora_user_uuid',
            'display_name',
            'avatar_url',
            'subscription_tier',
            'last_synced_at',
            'created_at',
            'updated_at',
        ];

        $actual = Schema::getColumnListing('dodo_users');
        $extras = array_diff($actual, $allowed);

        $this->assertSame(
            [],
            array_values($extras),
            '朵朵 dodo_users 加新欄位前請先評估：是不是真的不算 PII、是不是該放在 platform？'
            .' 多出的欄位：'.implode(', ', $extras),
        );
    }
}
