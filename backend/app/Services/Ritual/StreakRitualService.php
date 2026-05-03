<?php

namespace App\Services\Ritual;

use App\Models\HealthMetric;
use App\Models\Meal;
use App\Models\ProgressSnapshot;
use App\Models\RitualEvent;
use App\Models\User;
use App\Services\HealthMetricsService;
use Carbon\CarbonImmutable;

/**
 * SPEC-progress-ritual-v1 PR #8 — central streak detector + ritual fire.
 *
 * PR #6 wired only fasting streak (FastingService). This service covers
 * meal / weight log / progress photo streaks too. Called from the
 * respective controllers after a record is inserted.
 *
 * Compliance: 30/60/100/365 round-number-only firing (same as
 * StreakMilestone30Rule + ritual_streak idempotency).
 */
class StreakRitualService
{
    private const MILESTONE_DAYS = [30, 60, 100, 365];

    public function __construct(
        private readonly RitualDispatcher $dispatcher,
    ) {}

    public function checkMealStreak(User $user, ?CarbonImmutable $now = null): void
    {
        $streak = $this->consecutiveMealDays($user, $now ?? CarbonImmutable::now('Asia/Taipei'));
        $this->maybeFire($user, 'meal', $streak);
    }

    public function checkWeightLogStreak(User $user, ?CarbonImmutable $now = null): void
    {
        $streak = $this->consecutiveWeightLogDays($user, $now ?? CarbonImmutable::now('Asia/Taipei'));
        $this->maybeFire($user, 'weight_log', $streak);
    }

    public function checkPhotoStreak(User $user, ?CarbonImmutable $now = null): void
    {
        $streak = $this->consecutivePhotoDays($user, $now ?? CarbonImmutable::now('Asia/Taipei'));
        $this->maybeFire($user, 'photo', $streak);
    }

    private function maybeFire(User $user, string $kind, int $streak): void
    {
        if (! in_array($streak, self::MILESTONE_DAYS, true)) {
            return;
        }
        $this->dispatcher->dispatch(
            $user,
            RitualEvent::KEY_STREAK_MILESTONE,
            "{$kind}_streak:{$user->id}:{$streak}",
            ['streak_kind' => $kind, 'streak_count' => $streak],
        );
    }

    private function consecutiveMealDays(User $user, CarbonImmutable $today): int
    {
        // Past 60 days enough to cover 30/60 milestones.
        $dates = Meal::query()
            ->where('user_id', $user->id)
            ->where('date', '>=', $today->subDays(70)->toDateString())
            ->pluck('date')
            ->map(fn ($d) => is_string($d) ? $d : $d->toDateString())
            ->unique()
            ->values()
            ->all();

        return $this->countConsecutiveBack($today, $dates);
    }

    private function consecutiveWeightLogDays(User $user, CarbonImmutable $today): int
    {
        $dates = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', HealthMetricsService::TYPE_WEIGHT)
            ->where('recorded_at', '>=', $today->subDays(370))
            ->get(['recorded_at'])
            ->map(fn ($r) => $r->recorded_at->setTimezone('Asia/Taipei')->toDateString())
            ->unique()
            ->values()
            ->all();

        return $this->countConsecutiveBack($today, $dates);
    }

    private function consecutivePhotoDays(User $user, CarbonImmutable $today): int
    {
        // Photo cadence is weekly-ish, but for streak purposes any day with
        // a snapshot counts. 365-day window for the 1-year milestone.
        $dates = ProgressSnapshot::query()
            ->where('user_id', $user->id)
            ->where('taken_at', '>=', $today->subDays(370))
            ->get(['taken_at'])
            ->map(fn ($r) => $r->taken_at->setTimezone('Asia/Taipei')->toDateString())
            ->unique()
            ->values()
            ->all();

        return $this->countConsecutiveBack($today, $dates);
    }

    /** @param  array<int, string>  $dates */
    private function countConsecutiveBack(CarbonImmutable $today, array $dates): int
    {
        $set = array_flip($dates);
        $cursor = $today->startOfDay();
        if (! isset($set[$cursor->toDateString()])) {
            return 0;
        }
        $streak = 0;
        while (isset($set[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }
}
