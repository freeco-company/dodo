<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Long-term growth view for the home tab + 「每週回顧」page.
 *
 * Reads from existing daily_logs (already has weight_kg, macros, hydration, exercise).
 * No new schema needed for v1. Future iterations may extract a body_records table
 * if multi-measure-per-day is required.
 *
 * Two reads:
 *   timeseries() — flat (date, value) for chart components
 *   weeklyReview() — current vs previous 7-day rollups + 朵朵語氣 commentary
 *
 * 朵朵 commentary is rule-based (not AI) — deterministic, free, fits in tests.
 * Phase 5+ may upgrade to per-user AI insight when AI cost guard allows.
 */
class GrowthService
{
    public const SUPPORTED_METRICS = [
        'weight_kg',
        'total_calories',
        'total_protein_g',
        'total_carbs_g',
        'total_fat_g',
        'total_fiber_g',
        'water_ml',
        'exercise_minutes',
        'total_score',
    ];

    public function timeseries(User $user, string $metric, int $days): array
    {
        if (! in_array($metric, self::SUPPORTED_METRICS, true)) {
            $metric = 'weight_kg';
        }

        $days = max(1, min($days, 365));
        $end = Carbon::today()->toImmutable();
        $start = $end->subDays($days - 1);

        $rows = DailyLog::query()
            ->where('user_id', $user->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date')
            ->get();

        return $this->densify($rows, $metric, $start, $end);
    }

    public function weeklyReview(User $user, ?CarbonImmutable $weekStart = null): array
    {
        $weekStart ??= Carbon::today()->toImmutable()->subDays(6);
        $weekEnd = $weekStart->addDays(6);
        $prevStart = $weekStart->subDays(7);
        $prevEnd = $weekStart->subDay();

        $current = $this->aggregate($user, $weekStart, $weekEnd);
        $previous = $this->aggregate($user, $prevStart, $prevEnd);
        $deltas = $this->deltas($current, $previous);

        return [
            'window' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
            ],
            'previous_window' => [
                'start' => $prevStart->toDateString(),
                'end' => $prevEnd->toDateString(),
            ],
            'current' => $current,
            'previous' => $previous,
            'deltas' => $deltas,
            'dodo_commentary' => $this->commentary($current, $deltas),
        ];
    }

    private function aggregate(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DailyLog::query()
            ->where('user_id', $user->id)
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->get();

        $weightRows = $rows->whereNotNull('weight_kg');

        return [
            'days_logged' => $rows->count(),
            'meals_logged' => (int) $rows->sum('meals_logged'),
            'avg_calories' => $rows->isEmpty() ? null : round($rows->avg('total_calories'), 0),
            'avg_protein_g' => $rows->isEmpty() ? null : round((float) $rows->avg('total_protein_g'), 1),
            'avg_water_ml' => $rows->isEmpty() ? null : round($rows->avg('water_ml'), 0),
            'avg_exercise_minutes' => $rows->isEmpty() ? null : round($rows->avg('exercise_minutes'), 1),
            'avg_score' => $rows->isEmpty() ? null : round($rows->avg('total_score'), 1),
            'weight_start' => $weightRows->isEmpty() ? null : (float) $weightRows->sortBy('date')->first()->weight_kg,
            'weight_end' => $weightRows->isEmpty() ? null : (float) $weightRows->sortByDesc('date')->first()->weight_kg,
            'weight_logs' => $weightRows->count(),
        ];
    }

    private function deltas(array $current, array $previous): array
    {
        $out = [];
        foreach (['avg_calories', 'avg_protein_g', 'avg_water_ml', 'avg_score', 'meals_logged', 'days_logged'] as $key) {
            $cur = $current[$key];
            $prev = $previous[$key];
            $out[$key] = ($cur === null || $prev === null) ? null : round($cur - $prev, 1);
        }

        $weightDelta = null;
        if ($current['weight_start'] !== null && $current['weight_end'] !== null) {
            $weightDelta = round($current['weight_end'] - $current['weight_start'], 2);
        }
        $out['weight_change_kg'] = $weightDelta;

        return $out;
    }

    /**
     * Rule-based 朵朵 voice commentary. 4 short sentences max — fits speech bubble.
     *
     * Tone: warm, encouraging, never judgmental. 妳 / 朋友 — never 您 / 會員.
     * Per group-naming-and-voice.md.
     */
    private function commentary(array $current, array $deltas): array
    {
        $lines = [];

        if ($current['days_logged'] === 0) {
            return [
                'headline' => '這週還沒開始記錄呢～',
                'lines' => ['朵朵在這裡等妳，從今天的早餐開始也不嫌晚 ✨'],
            ];
        }

        if ($current['days_logged'] >= 6) {
            $lines[] = "這週記錄了 {$current['days_logged']} 天，超棒的堅持 💪";
        } elseif ($current['days_logged'] >= 3) {
            $lines[] = "這週記錄了 {$current['days_logged']} 天，下週試試看再多 1-2 天 🌱";
        } else {
            $lines[] = "這週記錄 {$current['days_logged']} 天，朵朵會在妳身邊一起努力 🌷";
        }

        if ($current['avg_protein_g'] !== null && $current['avg_protein_g'] >= 60) {
            $lines[] = "蛋白質平均 {$current['avg_protein_g']}g，達標！這對體態管理超關鍵。";
        } elseif ($current['avg_protein_g'] !== null) {
            $lines[] = "蛋白質平均 {$current['avg_protein_g']}g，可以再多一點點唷～";
        }

        if (($deltas['weight_change_kg'] ?? null) !== null && abs($deltas['weight_change_kg']) >= 0.1) {
            $verb = $deltas['weight_change_kg'] < 0 ? '減少' : '增加';
            $abs = abs($deltas['weight_change_kg']);
            $lines[] = "體重{$verb} {$abs}kg — 趨勢比單次數字更重要，繼續記錄就好 📈";
        }

        if (count($lines) < 2) {
            $lines[] = '一週一週累積，妳已經在路上了。';
        }

        return [
            'headline' => $current['days_logged'] >= 5 ? '本週表現亮眼' : '繼續加油',
            'lines' => array_slice($lines, 0, 3),
        ];
    }

    private function densify(Collection $rows, string $metric, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $byDate = $rows->keyBy(fn ($r) => $r->date->toDateString());
        $out = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $key = $cursor->toDateString();
            $row = $byDate->get($key);
            $value = $row ? $row->{$metric} : null;
            if ($value !== null && in_array($metric, ['weight_kg', 'total_protein_g', 'total_carbs_g', 'total_fat_g', 'total_fiber_g'], true)) {
                $value = (float) $value;
            } elseif ($value !== null) {
                $value = (int) $value;
            }
            $out[] = ['date' => $key, 'value' => $value];
            $cursor = $cursor->addDay();
        }

        return $out;
    }
}
