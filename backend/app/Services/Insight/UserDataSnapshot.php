<?php

namespace App\Services\Insight;

use Carbon\CarbonImmutable;

/**
 * SPEC-cross-metric-insight-v1 PR #1 — frozen snapshot of a user's
 * 7-day / 14-day window. All InsightRule::evaluate() implementations
 * read from this struct; aggregator builds it once per user per run.
 */
final class UserDataSnapshot
{
    /**
     * @param  array<string,mixed>  $weight  ['series_kg' => [['date'=>..., 'kg'=>...]],
     *     'avg_7d'=>?, 'avg_prev_7d'=>?, 'sd_7d'=>?]
     * @param  array<string,mixed>  $sleep   ['avg_minutes_7d'=>?, 'avg_minutes_prev_7d'=>?]
     * @param  array<string,mixed>  $steps   ['total_7d'=>?, 'total_prev_7d'=>?]
     * @param  array<string,mixed>  $meals   ['avg_kcal_7d'=>?, 'sd_kcal_7d'=>?, 'days_logged_7d'=>?,
     *     'avg_protein_g_7d'=>?, 'days_protein_above_target_7d'=>?]
     * @param  array<string,mixed>  $fasting ['streak_days'=>?, 'days_completed_7d'=>?]
     * @param  array<string,mixed>  $streaks ['meal_streak'=>?, 'fasting_streak'=>?, 'steps_streak'=>?,
     *     'weight_log_streak'=>?, 'photo_streak'=>?]
     */
    public function __construct(
        public readonly int $userId,
        public readonly CarbonImmutable $now,
        public readonly array $weight = [],
        public readonly array $sleep = [],
        public readonly array $steps = [],
        public readonly array $meals = [],
        public readonly array $fasting = [],
        public readonly array $streaks = [],
    ) {}

    public function weightAvg7d(): ?float
    {
        $v = $this->weight['avg_7d'] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }

    public function weightAvgPrev7d(): ?float
    {
        $v = $this->weight['avg_prev_7d'] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }

    public function weightSd7d(): ?float
    {
        $v = $this->weight['sd_7d'] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }

    public function sleepAvgMinutes7d(): ?float
    {
        $v = $this->sleep['avg_minutes_7d'] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }

    public function steps7d(): ?int
    {
        $v = $this->steps['total_7d'] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    public function stepsPrev7d(): ?int
    {
        $v = $this->steps['total_prev_7d'] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    public function mealsKcalAvg7d(): ?float
    {
        $v = $this->meals['avg_kcal_7d'] ?? null;

        return is_numeric($v) ? (float) $v : null;
    }

    public function mealsKcalSdRatio7d(): ?float
    {
        $sd = is_numeric($this->meals['sd_kcal_7d'] ?? null) ? (float) $this->meals['sd_kcal_7d'] : null;
        $avg = $this->mealsKcalAvg7d();
        if ($sd === null || $avg === null || $avg <= 0) {
            return null;
        }

        return $sd / $avg;
    }

    public function fastingStreakDays(): ?int
    {
        $v = $this->fasting['streak_days'] ?? null;

        return is_numeric($v) ? (int) $v : null;
    }

    /** any of the configured streak categories (meal/fasting/steps/weight/photo). */
    public function maxStreakDays(): int
    {
        $vals = array_values($this->streaks);
        $vals = array_map('intval', array_filter($vals, 'is_numeric'));

        return $vals === [] ? 0 : max($vals);
    }
}
