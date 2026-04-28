<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FunnelOverviewWidget;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Widgets\WidgetConfiguration;

/**
 * /admin/funnel — ADR-003 §1.1 加盟漏斗 lifecycle 視覺化。
 *
 * v1：header ASCII 漏斗 + FunnelOverviewWidget + 30 天 transition log placeholder。
 *
 * 後續（不在本 PR）：
 *   - transition log table 接 py-service /funnel/transitions（端點還沒）
 *   - 多 App 切換（doudou / fp / 學院）
 *   - 時間區間篩選（7d / 30d / 90d）
 *   - export CSV（給 PM / BD）
 */
class FunnelDashboard extends Page
{
    protected static ?string $navigationLabel = '加盟漏斗';

    protected static ?string $title = '加盟漏斗 Dashboard';

    protected static string|\UnitEnum|null $navigationGroup = '漏斗';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-funnel';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'funnel';

    protected string $view = 'filament.pages.funnel-dashboard';

    /**
     * @return array<class-string|WidgetConfiguration>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            FunnelOverviewWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
