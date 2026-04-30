<?php

namespace App\Filament\Pages;

use App\Models\DailyLog;
use App\Models\Meal;
use App\Models\User;
use App\Services\GrowthService;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

/**
 * /admin/client-observation — 加盟者經營後盾 ✨
 *
 * 對映集團「仙女培訓地圖」STEP 04「客戶專屬 APP」+ 經營後盾。
 *
 * 給加盟者 / BD / 客服看單一客戶的飲食 + 體重 + 運動進度，幫他們判斷：
 *   - 客戶是否還在規律使用？
 *   - 體重 / 飲食有沒有進步？
 *   - 該推什麼產品？（簡單 heuristic — 蛋白低就推蛋白粉等）
 *
 * 重要 UX 邊界（同 FunnelDashboard）：
 *   - 不是行銷名單，不要自動發訊
 *   - 客戶 opt-out 後絕不用此資料行銷
 *   - 此頁是給「想關心客戶的加盟者人工查」用，不是 outbound 工具
 *
 * v1 範圍：
 *   - 用 Email / Name 搜尋單一 user
 *   - 顯示：基本資料 / 30 天體重曲線 / 7 天每日分數 / 最近 7 餐 / 簡單產品建議
 *
 * 之後可加：
 *   - 加盟者 → 我的客戶 list（需要 partner_customer_link 表）
 *   - 推薦此篇知識文章給此客戶（需要 Phase 5 KB）
 *   - 體重達標 / 連續達標等更複雜 milestone alert
 */
class ClientObservation extends Page
{
    protected static ?string $navigationLabel = '客戶觀察（加盟者後盾）';

    protected static ?string $title = '客戶觀察 / 經營後盾';

    protected static string|\UnitEnum|null $navigationGroup = '漏斗';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-eye';

    protected static ?int $navigationSort = 96;

    protected static ?string $slug = 'client-observation';

    protected string $view = 'filament.pages.client-observation';

    public ?string $search = '';

    public ?int $selectedUserId = null;

    public function mount(): void
    {
        $this->search = (string) request()->query('q', '');
        if ($this->search !== '') {
            $this->doSearch();
        }
    }

    public function doSearch(): void
    {
        if (trim($this->search) === '') {
            $this->selectedUserId = null;

            return;
        }
        $u = User::query()
            ->where('email', 'like', "%{$this->search}%")
            ->orWhere('name', 'like', "%{$this->search}%")
            ->orWhere('pandora_user_uuid', $this->search)
            ->orderByDesc('id')
            ->first();
        $this->selectedUserId = $u?->id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function clientData(): ?array
    {
        if (! $this->selectedUserId) {
            return null;
        }

        $user = User::find($this->selectedUserId);
        if (! $user) {
            return null;
        }

        /** @var GrowthService $growth */
        $growth = app(GrowthService::class);
        $weightSeries = $growth->timeseries($user, 'weight_kg', 30);
        $weeklyReview = $growth->weeklyReview($user);

        $last7Days = DailyLog::where('user_id', $user->id)
            ->whereDate('date', '>=', now()->subDays(6)->toDateString())
            ->orderByDesc('date')
            ->get();

        $recentMeals = Meal::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(7)
            ->get();

        $suggestions = $this->productSuggestions($user, $weeklyReview);

        return [
            'user' => $user,
            'weight_series' => $weightSeries,
            'weekly_review' => $weeklyReview,
            'last_7_days' => $last7Days,
            'recent_meals' => $recentMeals,
            'suggestions' => $suggestions,
            'sparkline_path' => $this->sparklinePath($weightSeries),
        ];
    }

    /**
     * Simple rule-based product suggestion engine.
     *
     * Map weekly review trends → 婕樂纖 product line. Rules are deliberately
     * conservative — never suggest more than 2 items at once, never claim
     * medical effect.
     *
     * Phase 5+ may upgrade to KB-driven (knowledge_articles tag → product
     * recommendation) or AI-driven (cost-guarded).
     *
     * @return list<array<string, string>>
     */
    private function productSuggestions(User $user, array $weeklyReview): array
    {
        $current = $weeklyReview['current'] ?? [];
        $out = [];

        $avgProtein = $current['avg_protein_g'] ?? null;
        if ($avgProtein !== null && $avgProtein < 60) {
            $out[] = [
                'label' => '蛋白質補充建議',
                'reason' => "本週平均蛋白質 {$avgProtein}g，未達 60g 起跳線",
                'product' => '婕樂纖蛋白系列',
                'tone' => '可以聊聊「最近吃到的高蛋白食物」開場，不要 push',
            ];
        }

        $avgWater = $current['avg_water_ml'] ?? null;
        if ($avgWater !== null && $avgWater < 1500) {
            $out[] = [
                'label' => '水分提醒建議',
                'reason' => "本週平均喝水 {$avgWater}ml，建議 1500ml 起跳",
                'product' => '提醒每日喝水（先不推產品）',
                'tone' => '輕鬆關心：「最近天氣怎樣？水有沒有喝夠？」',
            ];
        }

        $daysLogged = $current['days_logged'] ?? 0;
        if ($daysLogged < 3) {
            $out[] = [
                'label' => '使用度警示',
                'reason' => "本週只記錄 {$daysLogged} 天，疏於使用",
                'product' => '不要推產品 — 先重啟使用',
                'tone' => '關心而非催促：「最近忙嗎？需要朵朵幫忙提醒妳記錄嗎？」',
            ];
        }

        if (empty($out)) {
            $out[] = [
                'label' => '狀態良好',
                'reason' => '本週各項指標都在合理範圍',
                'product' => '不需推產品',
                'tone' => '單純鼓勵：「妳這週做得很棒，繼續保持」',
            ];
        }

        return array_slice($out, 0, 2);
    }

    /**
     * Build SVG path d= for sparkline visualization.
     */
    private function sparklinePath(array $series): string
    {
        $valid = array_values(array_filter($series, fn ($p) => $p['value'] !== null));
        if (count($valid) < 2) {
            return '';
        }
        $values = array_map(fn ($p) => (float) $p['value'], $valid);
        $min = min($values);
        $max = max($values);
        $range = ($max - $min) ?: 1;

        $w = 600;
        $h = 80;
        $pad = 4;
        $xStep = ($w - $pad * 2) / max(count($series) - 1, 1);

        $parts = [];
        $first = true;
        foreach ($series as $i => $p) {
            if ($p['value'] === null) {
                $first = true;
                continue;
            }
            $x = $pad + $i * $xStep;
            $y = $h - $pad - ((float) $p['value'] - $min) / $range * ($h - $pad * 2);
            $parts[] = ($first ? 'M' : 'L') . ' ' . round($x, 1) . ' ' . round($y, 1);
            $first = false;
        }

        return implode(' ', $parts);
    }
}
