<?php

namespace App\Services\Insight;

use App\Models\FastingSession;
use App\Models\HealthMetric;
use App\Models\Meal;
use App\Models\User;
use App\Services\HealthMetricsService;
use Carbon\CarbonImmutable;

/**
 * SPEC-cross-metric-insight-v1 PR #1 — build a UserDataSnapshot for one user.
 *
 * Window: 7-day (current vs prior). All time math in Asia/Taipei to keep
 * day-buckets stable for the user. New users without enough data → fields
 * stay null; rules skip themselves rather than fire on noise (SPEC §12).
 */
class UserDataAggregator
{
    public function snapshotFor(User $user, ?CarbonImmutable $now = null): UserDataSnapshot
    {
        $now ??= CarbonImmutable::now('Asia/Taipei');
        $startCurr = $now->subDays(6)->startOfDay();
        $endCurr = $now->endOfDay();
        $startPrev = $now->subDays(13)->startOfDay();
        $endPrev = $now->subDays(7)->endOfDay();

        return new UserDataSnapshot(
            userId: $user->id,
            now: $now,
            weight: $this->weight($user, $startCurr, $endCurr, $startPrev, $endPrev),
            sleep: $this->sleep($user, $startCurr, $endCurr, $startPrev, $endPrev),
            steps: $this->steps($user, $startCurr, $endCurr, $startPrev, $endPrev),
            meals: $this->meals($user, $startCurr, $endCurr),
            fasting: $this->fasting($user, $startCurr, $endCurr),
            streaks: $this->streaks($user, $now),
        );
    }

    /**
     * @return array{series_kg: array<int,array{date:string,kg:float}>, avg_7d: ?float,
     *     avg_prev_7d: ?float, sd_7d: ?float}
     */
    private function weight(User $user, CarbonImmutable $sCurr, CarbonImmutable $eCurr, CarbonImmutable $sPrev, CarbonImmutable $ePrev): array
    {
        $rows = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_WEIGHT)
            ->whereBetween('recorded_at', [$sPrev, $eCurr])
            ->orderBy('recorded_at')
            ->get(['value', 'recorded_at']);

        $curr = $rows->filter(fn ($r) => $r->recorded_at >= $sCurr)->pluck('value')->map('floatval')->all();
        $prev = $rows->filter(fn ($r) => $r->recorded_at >= $sPrev && $r->recorded_at <= $ePrev)->pluck('value')->map('floatval')->all();

        // PR #2 — 4-week stability: max(daily kg) - min(daily kg) over past 28 days.
        $rows4w = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_WEIGHT)
            ->where('recorded_at', '>=', $sCurr->subDays(21))
            ->pluck('value')
            ->map('floatval')
            ->all();
        $maxDelta4w = count($rows4w) >= 4 ? round(max($rows4w) - min($rows4w), 2) : null;

        return [
            'series_kg' => $rows->map(fn ($r) => [
                'date' => $r->recorded_at->toDateString(),
                'kg' => (float) $r->value,
            ])->all(),
            'avg_7d' => $curr === [] ? null : round(array_sum($curr) / count($curr), 2),
            'avg_prev_7d' => $prev === [] ? null : round(array_sum($prev) / count($prev), 2),
            'sd_7d' => count($curr) >= 3 ? $this->stddev($curr) : null,
            'max_delta_4w' => $maxDelta4w,
        ];
    }

    private function sleep(User $user, CarbonImmutable $sCurr, CarbonImmutable $eCurr, CarbonImmutable $sPrev, CarbonImmutable $ePrev): array
    {
        $rows = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_SLEEP_MINUTES)
            ->whereBetween('recorded_at', [$sPrev, $eCurr])
            ->get(['value', 'recorded_at']);

        $curr = $rows->filter(fn ($r) => $r->recorded_at >= $sCurr)->pluck('value')->map('floatval')->all();
        $prev = $rows->filter(fn ($r) => $r->recorded_at >= $sPrev && $r->recorded_at <= $ePrev)->pluck('value')->map('floatval')->all();

        return [
            'avg_minutes_7d' => $curr === [] ? null : round(array_sum($curr) / count($curr), 1),
            'avg_minutes_prev_7d' => $prev === [] ? null : round(array_sum($prev) / count($prev), 1),
        ];
    }

    private function steps(User $user, CarbonImmutable $sCurr, CarbonImmutable $eCurr, CarbonImmutable $sPrev, CarbonImmutable $ePrev): array
    {
        $rows = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_STEPS)
            ->whereBetween('recorded_at', [$sPrev, $eCurr])
            ->get(['value', 'recorded_at']);

        $curr = $rows->filter(fn ($r) => $r->recorded_at >= $sCurr)->pluck('value')->map('intval')->sum();
        $prev = $rows->filter(fn ($r) => $r->recorded_at >= $sPrev && $r->recorded_at <= $ePrev)->pluck('value')->map('intval')->sum();

        // PR #2 — daily target met (default 8000 steps; could become user pref later).
        $byDay = $rows->filter(fn ($r) => $r->recorded_at >= $sCurr)->groupBy(fn ($r) => $r->recorded_at->setTimezone('Asia/Taipei')->toDateString());
        $daysMetTarget = $byDay->filter(fn ($g) => $g->sum(fn ($r) => (float) $r->value) >= 8000)->count();

        return [
            'total_7d' => $rows->filter(fn ($r) => $r->recorded_at >= $sCurr)->isEmpty() ? null : (int) $curr,
            'total_prev_7d' => $rows->filter(fn ($r) => $r->recorded_at >= $sPrev && $r->recorded_at <= $ePrev)->isEmpty() ? null : (int) $prev,
            'days_met_target_7d' => $daysMetTarget,
        ];
    }

    private function meals(User $user, CarbonImmutable $sCurr, CarbonImmutable $eCurr): array
    {
        $meals = Meal::query()
            ->where('user_id', $user->id)
            ->whereBetween('date', [$sCurr->toDateString(), $eCurr->toDateString()])
            ->get(['date', 'calories', 'protein_g']);

        if ($meals->isEmpty()) {
            return [
                'avg_kcal_7d' => null,
                'sd_kcal_7d' => null,
                'days_logged_7d' => 0,
                'avg_protein_g_7d' => null,
            ];
        }

        // Meal::$date is `cast: 'date'` so it's Carbon at runtime; the @property
        // hint in Meal happens to declare it as string for legacy reasons. Pull
        // the raw attribute and let Carbon parse to be type-safe both ways.
        $byDay = $meals->groupBy(fn ($m) => \Illuminate\Support\Carbon::parse($m->date)->toDateString());
        $dailyKcal = $byDay->map(fn ($g) => (float) $g->sum('calories'))->values()->all();
        $dailyProtein = $byDay->map(fn ($g) => (float) $g->sum('protein_g'))->values()->all();

        // PR #2 — late-night meals (after 21:00 Asia/Taipei).
        $lateNightCount = $meals->filter(function ($m) {
            $created = $m->created_at;

            return $created !== null && (int) $created->setTimezone('Asia/Taipei')->format('H') >= 21;
        })->count();

        // PR #2 — weekend (Sat/Sun) vs weekday kcal split.
        $weekendKcal = [];
        $weekdayKcal = [];
        foreach ($byDay as $dateStr => $group) {
            $dow = (int) \Illuminate\Support\Carbon::parse($dateStr)->dayOfWeek; // 0=Sun, 6=Sat
            $kcal = (float) $group->sum('calories');
            if ($dow === 0 || $dow === 6) {
                $weekendKcal[] = $kcal;
            } else {
                $weekdayKcal[] = $kcal;
            }
        }
        $weekendExcess = null;
        if ($weekendKcal !== [] && $weekdayKcal !== []) {
            $we = array_sum($weekendKcal) / count($weekendKcal);
            $wd = array_sum($weekdayKcal) / count($weekdayKcal);
            $weekendExcess = $wd > 0 ? round(($we - $wd) / $wd, 3) : null;
        }

        return [
            'avg_kcal_7d' => round(array_sum($dailyKcal) / count($dailyKcal), 1),
            'sd_kcal_7d' => count($dailyKcal) >= 2 ? $this->stddev($dailyKcal) : null,
            'days_logged_7d' => $byDay->count(),
            'avg_protein_g_7d' => round(array_sum($dailyProtein) / count($dailyProtein), 1),
            'late_night_count_7d' => $lateNightCount,
            'weekend_excess_ratio' => $weekendExcess,
        ];
    }

    private function fasting(User $user, CarbonImmutable $sCurr, CarbonImmutable $eCurr): array
    {
        $sessions = FastingSession::query()
            ->where('user_id', $user->id)
            ->where('completed', true)
            ->where('started_at', '>=', $sCurr->subDays(30))
            ->orderByDesc('started_at')
            ->get(['started_at', 'ended_at']);

        // streak = consecutive days back from today with at least 1 completed session
        $streak = 0;
        $cursor = $eCurr->startOfDay();
        $datesWithFasting = $sessions->map(fn ($s) => $s->started_at->setTimezone('Asia/Taipei')->toDateString())->unique()->values()->all();
        while (in_array($cursor->toDateString(), $datesWithFasting, true)) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        $daysCompleted7d = $sessions->filter(fn ($s) => $s->started_at >= $sCurr)->count();

        // PR #2 — late-night ended sessions (after 22:00 or before 04:00 Asia/Taipei)
        $lateBreaks7d = $sessions
            ->filter(fn ($s) => $s->ended_at !== null && $s->ended_at >= $sCurr)
            ->filter(function ($s) {
                $h = (int) $s->ended_at->setTimezone('Asia/Taipei')->format('H');

                return $h >= 22 || $h < 4;
            })
            ->count();

        return [
            'streak_days' => $streak,
            'days_completed_7d' => $daysCompleted7d,
            'late_breaks_7d' => $lateBreaks7d,
        ];
    }

    /** @return array<string,int> */
    private function streaks(User $user, CarbonImmutable $now): array
    {
        // Reuse fasting streak calc; meal streak = consecutive days with ≥1 meal logged.
        $today = $now->startOfDay();

        $mealDates = Meal::query()
            ->where('user_id', $user->id)
            ->where('date', '>=', $today->subDays(60)->toDateString())
            ->pluck('date')
            ->map(fn ($d) => $d->toDateString())
            ->unique()
            ->values()
            ->all();
        $mealStreak = 0;
        $cursor = $today;
        while (in_array($cursor->toDateString(), $mealDates, true)) {
            $mealStreak++;
            $cursor = $cursor->subDay();
        }

        $weightDates = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_WEIGHT)
            ->where('recorded_at', '>=', $today->subDays(60))
            ->get(['recorded_at'])
            ->map(fn ($r) => $r->recorded_at->setTimezone('Asia/Taipei')->toDateString())
            ->unique()
            ->values()
            ->all();
        $weightStreak = 0;
        $cursor = $today;
        while (in_array($cursor->toDateString(), $weightDates, true)) {
            $weightStreak++;
            $cursor = $cursor->subDay();
        }

        return [
            'meal_streak' => $mealStreak,
            'weight_log_streak' => $weightStreak,
        ];
    }

    /** @param  array<int,float>  $values */
    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1);

        return round(sqrt($variance), 2);
    }
}
