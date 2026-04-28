<?php

namespace App\Filament\Widgets;

use App\Services\Conversion\FunnelMetricsClient;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/**
 * 朵朵 admin 漏斗總覽 widget（ADR-003 §1.1）。
 *
 * 一個 Stat = 一個 lifecycle stage；副標顯示對「上一階段」的轉換率，
 * 例如 registered 副標 = registered / visitor。
 *
 * Cache：1 小時。漏斗本來就慢變化的指標，沒必要每次 admin 開頁都打一次 py-service。
 * 顏色漸進：visitor 灰 → franchisee 強色（success），暗示「越往下越值錢」。
 */
class FunnelOverviewWidget extends StatsOverviewWidget
{
    protected ?string $heading = '加盟漏斗 Lifecycle 分佈';

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
     * @var array<string, array{label: string, color: string}>
     */
    private const STAGE_META = [
        'visitor' => ['label' => 'Visitor 訪客', 'color' => 'gray'],
        'registered' => ['label' => 'Registered 註冊', 'color' => 'info'],
        'engaged' => ['label' => 'Engaged 活躍', 'color' => 'primary'],
        'loyalist' => ['label' => 'Loyalist 愛用者', 'color' => 'warning'],
        'applicant' => ['label' => 'Applicant 諮詢中', 'color' => 'danger'],
        'franchisee' => ['label' => 'Franchisee 加盟', 'color' => 'success'],
    ];

    protected function getColumns(): int
    {
        return 3;
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
