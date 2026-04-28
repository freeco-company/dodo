<?php

namespace App\Filament\Resources\FranchiseLeads\Pages;

use App\Filament\Resources\FranchiseLeads\FranchiseLeadResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Inbox 主畫面 — 必須在 header 大字提醒「人工聯繫，不要自動發訊」。
 *
 * 為什麼提醒寫死在 header（不是放 docs）：
 *   - admin user 不會去翻 docs；提醒必須在他每次打開頁面就看見
 *   - 防止新進業務以為這是「導出名單發訊」的工具
 */
class ListFranchiseLeads extends ListRecords
{
    protected static string $resource = FranchiseLeadResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        // 大字、紅底，視覺強烈但不嚇到人
        return new HtmlString(
            '<div style="background:#FEF3C7;border:2px solid #D97706;color:#7C2D12;'
            .'padding:14px 16px;border-radius:10px;font-size:15px;line-height:1.6;">'
            .'<strong style="font-size:17px;">⚠️ 這是內部分段資料，<u>不是</u>自動 outbound 列表。</strong><br>'
            .'請<strong>人工</strong>判斷脈絡後再聯繫客戶；<strong>不要自動發訊</strong>'
            .'（不發 email / 不發 LINE / 不發 SMS）。<br>'
            .'若使用者已主動 opt-out（status = silenced），<strong>絕對不要</strong>聯繫。'
            .'</div>'
        );
    }
}
