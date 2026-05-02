<?php

namespace App\Services;

use App\Exceptions\AiServiceUnavailableException;
use App\Models\FastingSession;
use App\Models\HealthMetric;
use App\Models\Meal;
use App\Models\User;
use App\Models\WeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * SPEC-weekly-ai-report Phase 1 — aggregate + cache + render the weekly
 * report payload (meals + fasting + health + 朵朵 narrative).
 *
 * Reuses the legacy `weekly_reports` table for cache + share counter.
 * The persisted `letter_content` field stores the deterministic 朵朵
 * narrative; ai-service narrative endpoint (paid tier) is a Phase 1.5
 * follow-up that overwrites it.
 */
class WeeklyReportService
{
    public function __construct(
        private readonly GrowthService $growth,
        private readonly EntitlementsService $entitlements,
        private readonly AiServiceClient $aiService,
    ) {}

    public function weekStartFor(CarbonImmutable $date): CarbonImmutable
    {
        // Spec §7 — week starts Sunday (locale-stable, share-friendly).
        // Carbon::SUNDAY = 0; startOfWeek(0) lands on the most recent Sunday.
        return $date->startOfWeek(0);
    }

    /**
     * Build (or fetch from cache) the report payload for the given week.
     * Always idempotent: regenerating overwrites payload + bumps generated_at.
     *
     * @return array<string,mixed>
     */
    public function generate(User $user, ?CarbonImmutable $weekStart = null): array
    {
        $weekStart = $this->weekStartFor($weekStart ?? CarbonImmutable::now('Asia/Taipei'));
        $weekEnd = $weekStart->addDays(6);
        $isPaid = $this->entitlements->isPaid($user);

        $meals = $this->aggregateMeals($user, $weekStart, $weekEnd);
        $fasting = $this->aggregateFasting($user, $weekStart, $weekEnd);
        $health = $this->aggregateHealth($user, $weekStart, $weekEnd, $isPaid);
        $growth = $this->growth->weeklyReview($user, $weekStart);

        $deterministic = $this->renderNarrative($meals, $fasting, $health, $growth, $isPaid);
        $narrative = $isPaid
            ? $this->maybeUpgradeNarrative($user, $weekStart, $weekEnd, $meals, $fasting, $health, $growth, $deterministic)
            : $deterministic;

        $payload = [
            'window' => [
                'start' => $weekStart->toDateString(),
                'end' => $weekEnd->toDateString(),
            ],
            'tier' => $isPaid ? 'paid' : 'free',
            'meals' => $meals,
            'fasting' => $fasting,
            'health' => $health,
            'growth' => [
                'weight_change_kg' => $growth['deltas']['weight_change_kg'] ?? null,
                'avg_score' => $growth['current']['avg_score'] ?? null,
                'days_logged' => $growth['current']['days_logged'] ?? 0,
            ],
            'narrative' => $narrative,
            'features' => [
                'image_card' => $isPaid,
                'history_unlimited' => $isPaid,
                'history_capped_weeks' => $isPaid ? null : 4,
                'sleep_visible' => $isPaid,
            ],
        ];

        $row = WeeklyReport::updateOrCreate(
            [
                'user_id' => $user->id,
                'week_start' => $weekStart->toDateString(),
            ],
            [
                'pandora_user_uuid' => $user->pandora_user_uuid,
                'week_end' => $weekEnd->toDateString(),
                'avg_score' => $growth['current']['avg_score'] ?? null,
                'daily_scores' => null,
                'weight_change' => $growth['deltas']['weight_change_kg'] ?? null,
                'top_foods' => $meals['top_foods'],
                'letter_content' => $narrative['headline']."\n".implode("\n", $narrative['lines']),
            ],
        );

        $payload['id'] = $row->id;
        $payload['shared_count'] = (int) $row->shared_count;
        $payload['generated_at'] = $row->updated_at?->toIso8601String();

        return $payload;
    }

    /**
     * @return list<array{week_start:string, week_end:string, avg_score:?float}>
     */
    public function history(User $user, int $weeks = 12): array
    {
        $isPaid = $this->entitlements->isPaid($user);
        $cap = $isPaid ? $weeks : min($weeks, 4);

        $rows = WeeklyReport::query()
            ->where('user_id', $user->id)
            ->orderByDesc('week_start')
            ->limit($cap)
            ->get(['id', 'week_start', 'week_end', 'avg_score', 'shared_count']);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => $r->id,
                'week_start' => $r->week_start->toDateString(),
                'week_end' => $r->week_end->toDateString(),
                'avg_score' => $r->avg_score !== null ? round((float) $r->avg_score, 1) : null,
                'shared_count' => (int) $r->shared_count,
            ];
        }

        return $out;
    }

    public function recordShared(WeeklyReport $report): int
    {
        $report->increment('shared_count');

        return (int) $report->fresh()->shared_count;
    }

    /**
     * @return array<string,mixed>
     */
    private function aggregateMeals(User $user, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $base = Meal::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()]);

        $count = (clone $base)->count();
        $totalKcal = (int) (clone $base)->sum('calories');

        $topRows = (clone $base)
            ->whereNotNull('food_name')
            ->selectRaw('food_name, COUNT(*) as ct')
            ->groupBy('food_name')
            ->orderByDesc('ct')
            ->limit(3)
            ->get();
        $top = [];
        foreach ($topRows as $r) {
            $top[] = ['name' => (string) $r->food_name, 'count' => (int) ($r->ct ?? 0)];
        }

        return [
            'count' => $count,
            'total_kcal' => $totalKcal,
            'top_foods' => $top,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function aggregateFasting(User $user, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): array
    {
        $rows = FastingSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->whereBetween('ended_at', [$weekStart->toDateTimeString(), $weekEnd->endOfDay()->toDateTimeString()])
            ->get(['target_duration_minutes', 'started_at', 'ended_at', 'completed']);

        $completed = $rows->where('completed', true);
        $longest = 0;
        $totalMinutes = 0;
        foreach ($rows as $r) {
            $start = CarbonImmutable::parse($r->started_at);
            $end = CarbonImmutable::parse($r->ended_at);
            $mins = max(0, $start->diffInMinutes($end));
            $totalMinutes += $mins;
            if ($mins > $longest) {
                $longest = $mins;
            }
        }

        return [
            'sessions' => $rows->count(),
            'completed' => $completed->count(),
            'longest_minutes' => $longest,
            'avg_minutes' => $rows->isEmpty() ? 0 : (int) round($totalMinutes / $rows->count()),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function aggregateHealth(User $user, CarbonImmutable $weekStart, CarbonImmutable $weekEnd, bool $isPaid): array
    {
        $start = $weekStart->toDateTimeString();
        $end = $weekEnd->endOfDay()->toDateTimeString();

        $stepsRows = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_STEPS)
            ->whereBetween('recorded_at', [$start, $end])
            ->get(['value']);

        $kcalRows = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_ACTIVE_KCAL)
            ->whereBetween('recorded_at', [$start, $end])
            ->get(['value']);

        $sleepAvg = null;
        if ($isPaid) {
            $sleepRows = HealthMetric::query()
                ->where('user_id', $user->id)
                ->where('type', HealthMetricsService::TYPE_SLEEP_MINUTES)
                ->whereBetween('recorded_at', [$start, $end])
                ->get(['value']);
            if ($sleepRows->isNotEmpty()) {
                $sleepSum = 0.0;
                foreach ($sleepRows as $row) {
                    $sleepSum += (float) $row->value;
                }
                $sleepAvg = (int) round($sleepSum / max(1, $sleepRows->count()));
            }
        }

        $stepsTotal = 0;
        foreach ($stepsRows as $row) {
            $stepsTotal += (int) $row->value;
        }
        $kcalTotal = 0;
        foreach ($kcalRows as $row) {
            $kcalTotal += (int) $row->value;
        }

        return [
            'total_steps' => $stepsTotal,
            'days_with_steps' => $stepsRows->count(),
            'total_active_kcal' => $kcalTotal,
            'avg_sleep_minutes' => $sleepAvg,
            'sleep_locked' => ! $isPaid,
        ];
    }

    /**
     * Phase 1.5 — call ai-service `/v1/reports/narrative` for paid users.
     * Fail-soft: any AiService error returns the deterministic fallback.
     *
     * @param  array<string,mixed>  $meals
     * @param  array<string,mixed>  $fasting
     * @param  array<string,mixed>  $health
     * @param  array<string,mixed>  $growth
     * @param  array{headline:string, lines:list<string>}  $fallback
     * @return array{headline:string, lines:list<string>}
     */
    private function maybeUpgradeNarrative(
        User $user,
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        array $meals,
        array $fasting,
        array $health,
        array $growth,
        array $fallback,
    ): array {
        if (! $this->aiService->isEnabled()) {
            return $fallback;
        }
        try {
            $payload = [
                'window_start' => $weekStart->toDateString(),
                'window_end' => $weekEnd->toDateString(),
                'days_logged' => (int) ($growth['current']['days_logged'] ?? 0),
                'meals_count' => (int) ($meals['count'] ?? 0),
                'meals_kcal' => (int) ($meals['total_kcal'] ?? 0),
                'top_foods' => array_slice(array_map(fn ($f) => (string) $f['name'], $meals['top_foods'] ?? []), 0, 5),
                'fasting_sessions' => (int) ($fasting['sessions'] ?? 0),
                'fasting_completed' => (int) ($fasting['completed'] ?? 0),
                'fasting_longest_minutes' => (int) ($fasting['longest_minutes'] ?? 0),
                'steps_total' => (int) ($health['total_steps'] ?? 0),
                'active_kcal_total' => (int) ($health['total_active_kcal'] ?? 0),
                'sleep_avg_minutes' => $health['avg_sleep_minutes'] ?? null,
                'weight_change_kg' => $growth['deltas']['weight_change_kg'] ?? null,
                'avg_score' => $growth['current']['avg_score'] ?? null,
            ];
            $tier = $this->entitlements->isPaid($user) ? 'paid' : 'free';
            $resp = $this->aiService->narrative($user, 'weekly_report', $tier, $payload);

            return [
                'headline' => $resp['headline'],
                'lines' => $resp['lines'],
            ];
        } catch (AiServiceUnavailableException $e) {
            Log::info('[WeeklyReport] ai narrative unavailable, using fallback', [
                'user_id' => $user->id,
                'reason' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * Phase 1 deterministic narrative (rule-based, free for all users).
     * Phase 1.5 follow-up: ai-service `/v1/reports/weekly-narrative` call
     * for paid users, with this output as the fallback.
     *
     * @return array{headline:string, lines:list<string>}
     */
    private function renderNarrative(
        array $meals,
        array $fasting,
        array $health,
        array $growth,
        bool $isPaid,
    ): array {
        $daysLogged = (int) ($growth['current']['days_logged'] ?? 0);
        if ($daysLogged === 0 && $meals['count'] === 0 && $fasting['sessions'] === 0 && $health['total_steps'] === 0) {
            return [
                'headline' => '這週還沒開始累積數據呢～',
                'lines' => [
                    '朵朵幫妳留好位置了 🌱',
                    '從今天的早餐 / 一段散步 / 一段斷食開始，下週就有故事可以看了 ✨',
                ],
            ];
        }

        $lines = [];
        if ($meals['count'] > 0) {
            $lines[] = "🍱 吃了 {$meals['count']} 餐 · 總計約 ".number_format($meals['total_kcal'])." kcal";
        }
        if ($health['total_steps'] > 0) {
            $lines[] = '🚶 走了 '.number_format($health['total_steps'])." 步（{$health['days_with_steps']} 天有資料）";
        }
        if ($fasting['sessions'] > 0) {
            $longestH = round($fasting['longest_minutes'] / 60, 1);
            $lines[] = "⏱️ 斷食 {$fasting['sessions']} 次，達標 {$fasting['completed']} 次（最長 {$longestH}h）";
        }
        $weightDelta = $growth['deltas']['weight_change_kg'] ?? null;
        if ($weightDelta !== null && (float) $weightDelta !== 0.0) {
            $sign = (float) $weightDelta < 0 ? '' : '+';
            $lines[] = "⚖️ 體重 {$sign}{$weightDelta}kg";
        }
        if ($health['avg_sleep_minutes'] !== null) {
            $h = intdiv($health['avg_sleep_minutes'], 60);
            $m = $health['avg_sleep_minutes'] % 60;
            $lines[] = "😴 平均睡眠 {$h}h {$m}m";
        }

        $closing = $isPaid
            ? '朵朵：「妳這週很穩定 🌱 下週試試看再多走 2,000 步、再記一次體重，我會幫妳看 trend ✨」'
            : '朵朵：「這週的數字都記下來了 🌷 升級訂閱可以看到更深的 AI 點評和分享圖卡喔」';

        return [
            'headline' => '朵朵的本週小報告 ✨',
            'lines' => array_merge($lines, [$closing]),
        ];
    }
}
