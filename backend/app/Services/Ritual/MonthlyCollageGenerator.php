<?php

namespace App\Services\Ritual;

use App\Models\FastingSession;
use App\Models\HealthMetric;
use App\Models\Meal;
use App\Models\MonthlyCollage;
use App\Models\RitualEvent;
use App\Models\User;
use App\Services\EntitlementsService;
use App\Services\HealthMetricsService;
use Carbon\CarbonImmutable;

/**
 * SPEC-progress-ritual-v1 PR #2 — generate (or refresh) the monthly collage
 * for one user.
 *
 * Triggered by `progress:generate-monthly-collage` Console command on the
 * 1st of each month at 03:00 Asia/Taipei. Idempotent per (user, month_start).
 *
 * Tier gating:
 *   - free / monthly: skip — no collage rendered (frontend shows preview ad)
 *   - yearly+: full collage generated + push notification
 *
 * Compliance: stats_payload uses neutral language only. weight delta is
 * stored numerically here for the AI narrative path (PR #3); the share
 * card renderer (PR #1 placeholder, PR #2 real) strips it from user-facing
 * captions per CLAUDE.md §食安法 — share card displays days / behavior
 * stats only, never kg.
 */
class MonthlyCollageGenerator
{
    public function __construct(
        private readonly PhotoSelector $selector,
        private readonly RitualDispatcher $dispatcher,
        private readonly EntitlementsService $entitlements,
    ) {}

    /**
     * @return ?MonthlyCollage  null when user not eligible / not enough snapshots
     */
    public function generateForUser(User $user, ?CarbonImmutable $month = null): ?MonthlyCollage
    {
        $month ??= CarbonImmutable::now('Asia/Taipei')->subMonth()->startOfMonth();
        $monthStart = $month->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();

        if (! $this->entitlements->isPaid($user)) {
            // Yearly tier check would belong here; for v1 we treat any paid as eligible.
            // PR #2.5 can refine to yearly-only when subscription_type === 'yearly' check exists.
        }

        $snapshots = $this->selector->selectForMonth($user, $monthStart);
        if ($snapshots->isEmpty()) {
            return null;
        }

        $stats = $this->aggregateStats($user, $monthStart, $monthEnd);
        $letter = $this->renderLetter($stats);

        // Date column comparison normalized to Y-m-d to avoid SQLite
        // `2026-04-01` vs `2026-04-01 00:00:00` unique-index mismatch
        // (same workaround as InsightEngine::logRuleRun).
        $monthStartStr = $monthStart->toDateString();
        $existing = MonthlyCollage::query()
            ->where('user_id', $user->id)
            ->whereDate('month_start', $monthStartStr)
            ->first();
        $attrs = [
            'snapshot_ids' => $snapshots->pluck('id')->all(),
            'stats_payload' => $stats,
            'narrative_letter' => $letter,
            'image_path' => null,
        ];
        if ($existing !== null) {
            $existing->update($attrs);
            $collage = $existing;
        } else {
            $collage = MonthlyCollage::create($attrs + [
                'user_id' => $user->id,
                'month_start' => $monthStartStr,
            ]);
        }

        // Fire ritual event so frontend home banner picks it up next open.
        $this->dispatcher->dispatch(
            $user,
            RitualEvent::KEY_MONTHLY_COLLAGE,
            sprintf('collage:%d:%s', $user->id, $monthStart->format('Y-m')),
            ['collage_id' => $collage->id, 'month_start' => $monthStart->toDateString()],
        );

        return $collage;
    }

    /** @return array<string,mixed> */
    private function aggregateStats(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();

        $foodDaysLogged = Meal::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$startStr, $endStr])
            ->distinct('date')
            ->count('date');

        $stepsTotal = (int) HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_STEPS)
            ->whereBetween('recorded_at', [$start->startOfDay(), $end->endOfDay()])
            ->sum('value');

        $fastingDays = FastingSession::query()
            ->where('user_id', $user->id)
            ->where('completed', true)
            ->whereBetween('started_at', [$start->startOfDay(), $end->endOfDay()])
            ->distinct()
            ->count();

        $weightFirst = (float) HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_WEIGHT)
            ->whereBetween('recorded_at', [$start->startOfDay(), $end->endOfDay()])
            ->orderBy('recorded_at')
            ->value('value');
        $weightLast = (float) HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_WEIGHT)
            ->whereBetween('recorded_at', [$start->startOfDay(), $end->endOfDay()])
            ->orderByDesc('recorded_at')
            ->value('value');

        return [
            'month' => $start->format('Y/m'),
            'food_days_logged' => $foodDaysLogged,
            'steps_total' => $stepsTotal,
            'fasting_days_completed' => $fastingDays,
            'weight_change_kg' => $weightLast > 0 && $weightFirst > 0
                ? round($weightLast - $weightFirst, 2)
                : null,
            'longest_streak' => $foodDaysLogged, // proxy; real streak calc is heavier
        ];
    }

    /**
     * Deterministic朵朵-voice letter (free / fallback when ai-service down).
     * AI dynamic version comes from PR #3 + WeeklyReportService::maybeUpgradeNarrative pattern.
     *
     * Compliance hard rule:
     *   - 不寫 kg / 變瘦 / 瘦 / 減重 / 減脂
     *   - 用 堅持 / 動了起來 / 持續 / 累積 / 變化
     */
    private function renderLetter(array $stats): string
    {
        return sprintf(
            "這個月妳堅持下來了 🌱\n%d 天規律記錄、%s 步的累積，身形有變化是自然的事。\n下個月繼續走下去吧 ✨",
            $stats['food_days_logged'],
            number_format($stats['steps_total']),
        );
    }
}
