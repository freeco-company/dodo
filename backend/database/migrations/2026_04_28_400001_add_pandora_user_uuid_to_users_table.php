<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D Wave 1 (ADR-007 §2.3) — legacy `users` 表掛上 pandora_user_uuid。
 *
 * 為什麼這支 migration 存在：
 *
 *   - Phase C 在 18 個 reference tables 上鋪了 nullable `pandora_user_uuid`
 *     dual-column rails，但 join key 的「源頭」legacy `users` 表本身還沒有
 *     這個欄位，因此 reference rows 沒辦法在寫入時抄下 uuid。
 *   - Wave 1 要做 1:1 mapping (legacy User ↔ DodoUser)，必須把 uuid 釘在
 *     legacy User 上，這樣 reference table observer 才有來源可讀。
 *
 * 為什麼 nullable + UNIQUE：
 *   - nullable：既有 user 還沒 backfill 出 mirror 之前該欄位是空的；
 *     `identity:backfill-mirror` 跑完才會全部填上。
 *   - UNIQUE：1:1 mapping 是 Wave 1 的硬約束 — 一個 legacy user 只能對應
 *     一個 DodoUser；MySQL 的 UNIQUE INDEX 允許多個 NULL，剛好相容
 *     backfill 期間。
 *
 * 為什麼 CHAR(36)：
 *   - 與 dodo_users.pandora_user_uuid 對齊（Phase C 已經是 CHAR(36)）。
 *   - 不用 BINARY(16) 的原因：人眼可讀 + 跨 service log / debug 方便，
 *     至於空間成本（每筆多 20 bytes）對 user 表規模無感。
 *
 * @see ADR-007 §2.3
 * @see app/Services/Identity/DodoUserSyncService::ensureMirror()
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->char('pandora_user_uuid', 36)
                ->nullable()
                ->unique('users_pandora_user_uuid_unique')
                ->after('legacy_id')
                ->comment('ADR-007 §2.3 — 1:1 link to dodo_users.pandora_user_uuid (Pandora Core identity)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_pandora_user_uuid_unique');
            $table->dropColumn('pandora_user_uuid');
        });
    }
};
