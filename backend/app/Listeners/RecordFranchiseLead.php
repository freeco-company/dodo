<?php

namespace App\Listeners;

use App\Events\ConversionEventPublished;
use App\Models\DodoUser;
use App\Models\FranchiseLead;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * 把指定的 conversion event 寫進 franchise_leads inbox（人工聯繫用）。
 *
 * 規則：
 *   - 只 record 「強訊號」事件：franchise.cta_click / mothership.first_order /
 *     mothership.consultation_submitted。其他事件（app.opened / engagement.deep）
 *     太常觸發、不該每次都灌進 BD inbox。
 *   - 同 user 同 trigger_event unique（migration 上 unique key），重複 fire
 *     走 update path，避免 BD 看到 100 條重複的 cta_click。
 *   - 若該 user 已 opt-out（dodo_users.franchise_cta_silenced = true），新的
 *     trigger 還是會建 lead，但 status 直接設 'silenced'，視覺上 inbox 預設
 *     filter 不顯示。理由：保留 audit trail（這人 opt-out 後又繼續用，也是
 *     一種訊號），但**絕不**讓 BD 用此資料聯繫。
 */
class RecordFranchiseLead
{
    /** @var list<string> */
    private const TRACKED_EVENTS = [
        'franchise.cta_click',
        'mothership.first_order',
        'mothership.consultation_submitted',
    ];

    public function handle(ConversionEventPublished $event): void
    {
        if (! in_array($event->eventType, self::TRACKED_EVENTS, true)) {
            return;
        }

        if ($event->pandoraUserUuid === '') {
            return;
        }

        // Listener 是 fire-and-forget — 任何 DB 錯誤（含 schema 還沒 migrate
        // 的單元測試環境）都不該炸到 publisher。BD inbox 容忍偶爾遺漏，
        // 但絕不能讓 conversion event 路徑因為 lead 寫入失敗而中斷。
        try {
            $this->record($event);
        } catch (QueryException $e) {
            Log::warning('[RecordFranchiseLead] DB error, skipping lead write', [
                'event_type' => $event->eventType,
                'uuid' => $event->pandoraUserUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function record(ConversionEventPublished $event): void
    {
        // legacy user_id fallback — phase F drop user_id 之前還是要一起寫
        $userId = User::query()
            ->where('pandora_user_uuid', $event->pandoraUserUuid)
            ->value('id');

        $silenced = (bool) DodoUser::query()
            ->whereKey($event->pandoraUserUuid)
            ->value('franchise_cta_silenced');

        FranchiseLead::query()->updateOrCreate(
            [
                'pandora_user_uuid' => $event->pandoraUserUuid,
                'trigger_event' => $event->eventType,
            ],
            [
                'user_id' => $userId,
                'source_app' => 'doudou',
                'trigger_payload' => $event->payload,
                // 既有 status 不覆寫（BD 可能已 mark contacting / contacted）；
                // 只有新建 row 走 default 'new' or 'silenced'.
                // updateOrCreate 的 update path 不會覆蓋 existing status，
                // 因為我們不在第二參數放 status —— 改用 firstOrCreate semantic.
            ],
        );

        // updateOrCreate 在 update path 不會更動 status / assigned_to / contacted_at，
        // 但若該 lead 是新建（status default 'new'）且 user 已 opt-out，
        // 我們要立刻把 status 切到 'silenced'。
        if ($silenced) {
            FranchiseLead::query()
                ->where('pandora_user_uuid', $event->pandoraUserUuid)
                ->where('trigger_event', $event->eventType)
                ->where('status', FranchiseLead::STATUS_NEW)
                ->update(['status' => FranchiseLead::STATUS_SILENCED]);
        }
    }
}
