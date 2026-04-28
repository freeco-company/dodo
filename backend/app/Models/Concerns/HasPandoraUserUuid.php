<?php

namespace App\Models\Concerns;

use App\Models\User;

/**
 * Phase D Wave 1 (ADR-007 §2.3) — reference-table model 的 dual-write trait。
 *
 * 行為：
 *   - 任何 saving (create / update) 事件觸發前，若該 row 已有 `user_id` 但
 *     `pandora_user_uuid` 還沒值，就從對應的 legacy User 抄一份 uuid 過來。
 *   - 已經有 uuid（不論是 service 自己塞的、或來自 platform JWT path）→ 不覆蓋。
 *   - user_id 為 null → 跳過（例如 ClientErrorController 匿名 path）。
 *
 * 為什麼用 saving 而非 creating：
 *   - saving 同時涵蓋 create 和 update，未來 Wave 2 service 改成寫 uuid 時，
 *     update 路徑也能一致補資料。
 *   - 如果 Wave 1 backfill 後突然有些 row 還缺 uuid，update 路徑能順手補上。
 *
 * 為什麼 query 不快取：
 *   - 一個 request 內通常只寫該 user 的少量 row；User::find 走 sanctum guard
 *     的 user instance 已 in-memory，再讀一次也命中 query log 不額外 round-trip。
 *   - 真正擔心成本時應該由 caller 在寫之前主動帶 uuid（這也是 Wave 2 的方向）。
 *
 * 套用方式：
 *   class DailyLog extends Model { use HasPandoraUserUuid; }
 *
 * @see ADR-007 §2.3 — strangler fig dual-column rails
 * @see App\Services\Identity\DodoUserSyncService::ensureMirror()
 */
trait HasPandoraUserUuid
{
    public static function bootHasPandoraUserUuid(): void
    {
        static::saving(function ($model) {
            // 已有 uuid → 不蓋；沒 user_id → 沒源可抄
            if (! empty($model->pandora_user_uuid)) {
                return;
            }
            if (empty($model->user_id)) {
                return;
            }

            $uuid = User::query()
                ->whereKey($model->user_id)
                ->value('pandora_user_uuid');

            if ($uuid !== null) {
                $model->pandora_user_uuid = $uuid;
            }
        });
    }
}
