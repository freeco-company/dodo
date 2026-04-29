<?php

namespace App\Filament\Resources\FranchiseLeads;

use App\Filament\Resources\FranchiseLeads\Pages\ListFranchiseLeads;
use App\Filament\Resources\FranchiseLeads\Tables\FranchiseLeadsTable;
use App\Models\FranchiseLead;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Filament admin Resource — franchise_leads inbox（人工聯繫用）。
 *
 * 重要 UX 邊界（在 Pages/ListFranchiseLeads 的 header 還會大字提醒一次）：
 *   - 這是**內部分段資料**，**不是**自動 outbound 列表
 *   - BD 必須**人工**判斷脈絡後才接觸客戶
 *   - 客人很敏感，避免「業務追殺感」
 *
 * 為什麼用 ->isLazy=false-ish 設計：
 *   - 預設 status filter 隱藏 'silenced' 列：BD 視覺上不會看到「opt-out 的人」
 *     (但資料還在，便於 audit)
 */
class FranchiseLeadResource extends Resource
{
    protected static ?string $model = FranchiseLead::class;

    protected static ?string $navigationLabel = 'Leads inbox';

    protected static ?string $modelLabel = '加盟 Lead';

    protected static ?string $pluralModelLabel = '加盟 Leads';

    protected static string|UnitEnum|null $navigationGroup = '漏斗';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox';

    protected static ?int $navigationSort = 95;

    public static function form(Schema $schema): Schema
    {
        // 不開放 admin 在 panel 上 free-form create / edit lead row
        // （資料來源是 conversion event listener；admin 透過 record actions
        //  改 status / 標 contacting / converted / dismissed / 覆寫 lifecycle 階段）。
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return FranchiseLeadsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFranchiseLeads::route('/'),
        ];
    }
}
