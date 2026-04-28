<?php

namespace App\Filament\Widgets;

use App\Services\Conversion\FunnelMetricsClient;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * 朵朵 admin 漏斗總覽 widget（ADR-008 §2.1）。
 *
 * 一個 Stat = 一個 lifecycle stage（5 個，兩段漏斗）；副標顯示對「上一階段」的轉換率，
 * 例如 loyalist 副標 = loyalist / visitor。
 *
 * Cache：1 小時。漏斗本來就慢變化的指標，沒必要每次 admin 開頁都打一次 py-service。
 * 顏色漸進：visitor 灰 → franchisee_active 強色，暗示「越往下越值錢」。
 *
 * py-service 部署順序提示：若 py-service 還回舊 6-stage 名（registered/engaged/franchisee），
 * FunnelMetricsClient::normalizeStages() 會把它們丟掉；本 widget 顯示新 5 stage = 0
 * 但不炸（ADR-008 §6 merge 順序自由）。
 */
class FunnelOverviewWidget extends StatsOverviewWidget
{
    protected ?string $heading = '加盟漏斗 Lifecycle 分佈（ADR-008 兩段）';

    protected int|string|array $columnSpan = 'full';

    /**
     * Render eagerly so Filament's SSR HTML contains the actual stat values.
     * Default `true` defers the data fetch to a Livewire hydration round-trip,
     * which makes feature tests can't assert the numbers in the initial response.
     */
    protected static bool $isLazy = false;

    /**
     * (label, color) per stage. order matters — drives the Stat sequence.
     *
     * Color palette mapping（ADR-008 task spec → Filament 內建 palette）：
     *   visitor 灰         → gray
     *   loyalist 黃        → warning
     *   applicant 橘       → danger（Filament 內建沒有 orange，danger 紅橘色最接近）
     *   self_use 紅        → primary（用 app primary 色，避免兩個都是 danger）
     *   active 紫          → success（Filament 內建沒有 purple；success 綠暗示「終點 / 達成」）
     *
     * 想要真正的 orange / red / purple 要在 panel 註冊 custom CSS Color::register()，
     * 屬於 Filament theming 範疇 — 留 deferred；當前以語義對應為主。
     *
     * @var array<string, array{label: string, color: string}>
     */
    private const STAGE_META = [
        'visitor' => ['label' => 'Visitor 訪客', 'color' => 'gray'],
        'loyalist' => ['label' => 'Loyalist 愛用者（連用 14 天+）', 'color' => 'warning'],
        'applicant' => ['label' => 'Applicant 諮詢中', 'color' => 'danger'],
        // 段 1 終點：婕樂纖後台首單成立
        'franchisee_self_use' => ['label' => 'Self-Use 加盟自用客', 'color' => 'primary'],
        // 段 2 終點：月進貨連續達標 OR 點仙女學院經營者入口
        'franchisee_active' => ['label' => 'Active 認真經營者', 'color' => 'success'],
    ];

    protected function getColumns(): int
    {
        // 5 stages 平均分佈；保留 5 col grid 讓每個 stat 自佔一格。
        return 5;
    }

    protected function getStats(): array
    {
        $metrics = Cache::remember(
            'funnel.metrics.overview',
            now()->addHour(),
            fn () => app(FunnelMetricsClient::class)->fetch(),
        );

        $stages = $metrics['stages'];
        $source = $metrics['source'];
        $previousCount = null;

        $stats = [];
        foreach (self::STAGE_META as $key => $meta) {
            $count = (int) ($stages[$key] ?? 0);

            $description = $previousCount === null
                ? $this->sourceLabel($source)
                : $this->conversionDescription($count, $previousCount);

            $stats[] = Stat::make($meta['label'], number_format($count))
                ->description($description)
                ->color($meta['color']);

            $previousCount = $count;
        }

        return $stats;
    }

    private function conversionDescription(int $count, int $previous): string
    {
        if ($previous <= 0) {
            return '上一階段 0';
        }

        $rate = ($count / $previous) * 100;

        return sprintf('轉換率 %s%% (vs 上一階段)', number_format($rate, 1));
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'stub' => '資料來源：stub fixture（py-service 未設定）',
            'error' => '資料來源：取得失敗，顯示 0',
            default => '資料來源：py-service /funnel/metrics',
        };
    }
}
