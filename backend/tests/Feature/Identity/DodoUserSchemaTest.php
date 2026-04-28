<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADR-007 §2.3 enforcement — 朵朵 dodo_users 的「身份 PII」永遠不能落地。
 *
 * Phase C 修正：原本的 whitelist test (`only_has_allowed_columns`) 過嚴 —
 * ADR-007 §2.3 原文允許 dodo_users mirror「uuid + display_name + avatar +
 * 該 App 必要欄位」，「該 App 必要欄位」literally 包含朵朵業務狀態
 * （gamification / health / progression / journey）。
 *
 * 改成 PII deny-list 形式：規範「什麼絕對不可以放」，剩下的允許。
 *
 * 命名邊界：
 *   ✅ birth_date — BMR / 卡路里計算必需，視為 health-tracking 業務欄位
 *   ❌ birthday — 身份系統用詞（生日問候、身份核對），屬 PII
 */
class DodoUserSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Hard deny-list — 任何一個落地到 dodo_users 都應該爆炸。
     *
     * Identity / Auth / Contact / Real-name 類欄位永遠由 Pandora Core 擁有，
     * 朵朵需要時透過 platform `GET /api/v1/users/{uuid}` 即時取（10s cache）。
     */
    private const FORBIDDEN_COLUMNS = [
        // contact PII
        'email',
        'email_canonical',
        'email_verified_at',
        'phone',
        'phone_canonical',

        // auth secrets
        'password',
        'password_hash',
        'remember_token',
        'api_token',

        // OAuth identifiers / tokens
        'oauth_token',
        'oauth_refresh_token',
        'access_token',
        'refresh_token',
        'google_id',
        'line_id',
        'apple_id',

        // address PII
        'address',
        'address_city',
        'address_district',
        'address_detail',

        // personal identification
        'real_name',     // 中文姓名 / legal name — 身份識別 PII
        'id_number',     // 身分證字號
        'birthday',      // 身份系統用詞 — 業務需要的健康欄位請用 birth_date
    ];

    public function test_dodo_users_table_forbids_pii_columns(): void
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
            'ADR-007 §2.3 violation: dodo_users 不能存身份 PII 欄位 ('.implode(', ', $forbidden).')。'
            .' PII 應透過 platform `GET /api/v1/users/{uuid}` 即時取得，不能落地到朵朵 DB。',
        );
    }

    /**
     * 確保 ADR-007 §2.3 的 4 個 identity mirror 欄位真的存在 — 防回退。
     */
    public function test_dodo_users_has_identity_mirror_columns(): void
    {
        $required = [
            'pandora_user_uuid',
            'display_name',
            'avatar_url',
            'subscription_tier',
            'last_synced_at',
        ];

        foreach ($required as $col) {
            $this->assertTrue(
                Schema::hasColumn('dodo_users', $col),
                "dodo_users missing identity mirror column `{$col}` (ADR-007 §2.3)",
            );
        }
    }
}
