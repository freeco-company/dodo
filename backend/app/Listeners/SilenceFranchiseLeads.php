<?php

namespace App\Listeners;

use App\Events\UserOptedOutFranchiseCta;
use App\Models\FranchiseLead;

/**
 * User 主動 opt-out 後，把 inbox 中該 user 還沒人工接觸的 lead 全部標為 silenced。
 *
 * 範圍只動 status='new' 的 row：
 *   - status='contacting' 表示 BD 已經拿起這條 lead，silence 後 BD 還是要結尾
 *     （不過後續禁止再發訊；那是 BD 規範，不是 code）
 *   - status='contacted' / 'converted' 已成歷史，不該被覆寫
 */
class SilenceFranchiseLeads
{
    public function handle(UserOptedOutFranchiseCta $event): void
    {
        if (! $event->silenced) {
            return;
        }

        FranchiseLead::query()
            ->where('pandora_user_uuid', $event->pandoraUserUuid)
            ->where('status', FranchiseLead::STATUS_NEW)
            ->update(['status' => FranchiseLead::STATUS_SILENCED]);
    }
}
