<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\Meal;
use App\Models\User;
use App\Services\Gamification\GamificationPublisher;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Re-aggregates DailyLog totals from Meal rows + recomputes daily score.
 *
 * Until ADR-009 Phase B.3 cuts pandora-meal over to py-service ledger as the
 * authority, the local `daily_logs` table needs to reflect the sum of the
 * day's meals so the daily score (calorie / protein / consistency components)
 * actually responds to meal writes. CheckinService's existing recalcScore
 * only sees water/exercise/weight columns; meal columns drift.
 *
 * This service is the single place that:
 *   1) re-sums (calories, protein, carbs, fat, fiber, sodium, sugar) +
 *      meals_logged for the given (user, date) from the meals table
 *   2) calls ScoringService::daily to compute the 0..100 total
 *   3) saves the DailyLog
 *   4) fires `meal.daily_score_80_plus` when score ≥ 80 (catalog §3.1).
 *      Server-side daily_cap_xp=15 + idempotency_key per (uuid, date) make
 *      the event once-per-day even if the score oscillates.
 */
class DailyLogAggregator
{
    public const DAILY_SCORE_THRESHOLD = 80;

    public function __construct(
        private readonly GamificationPublisher $gamification,
    ) {}

    /**
     * Recompute the DailyLog row for `(user, date)`.
     *
     * @return array{date:string, total_score:int, meals_logged:int}
     */
    public function recompute(User $user, ?string $date = null): array
    {
        $date ??= Carbon::today()->toDateString();
        $uuid = is_string($user->pandora_user_uuid) ? $user->pandora_user_uuid : '';

        return DB::transaction(function () use ($user, $date, $uuid): array {
            $log = DailyLog::where('pandora_user_uuid', $uuid !== '' ? $uuid : null)
                ->where(function ($q) use ($user, $uuid) {
                    if ($uuid !== '') {
                        $q->where('pandora_user_uuid', $uuid);
                    } else {
                        $q->where('user_id', $user->id);
                    }
                })
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->first()
                ?? DailyLog::create([
                    'user_id' => $user->id,
                    'pandora_user_uuid' => $uuid !== '' ? $uuid : null,
                    'date' => $date,
                ]);

            $totals = Meal::where(function ($q) use ($user, $uuid) {
                if ($uuid !== '') {
                    $q->where('pandora_user_uuid', $uuid);
                } else {
                    $q->where('user_id', $user->id);
                }
            })
                ->whereDate('date', $date)
                ->selectRaw(
                    'COUNT(*) as cnt, '.
                    'COALESCE(SUM(calories),0) as cal, '.
                    'COALESCE(SUM(protein_g),0) as p, '.
                    'COALESCE(SUM(carbs_g),0) as c, '.
                    'COALESCE(SUM(fat_g),0) as f, '.
                    'COALESCE(SUM(fiber_g),0) as fb'
                )
                ->first();

            // Note: daily_logs only has total_{calories,protein_g,carbs_g,fat_g,fiber_g}.
            // Sodium / sugar live on individual Meal rows (see daily_logs migration);
            // we don't roll them up to keep the schema unchanged.
            $log->meals_logged = (int) ($totals->cnt ?? 0);
            $log->total_calories = (int) ($totals->cal ?? 0);
            $log->total_protein_g = (float) ($totals->p ?? 0);
            $log->total_carbs_g = (float) ($totals->c ?? 0);
            $log->total_fat_g = (float) ($totals->f ?? 0);
            $log->total_fiber_g = (float) ($totals->fb ?? 0);

            $score = ScoringService::daily([
                'total_calories' => $log->total_calories,
                'daily_calorie_target' => $user->daily_calorie_target ?? 1800,
                'total_protein_g' => (float) $log->total_protein_g,
                'daily_protein_target_g' => $user->daily_protein_target_g ?? 80,
                'meals_logged' => $log->meals_logged,
                'exercise_minutes' => (int) $log->exercise_minutes,
                'water_ml' => (int) $log->water_ml,
            ]);
            $log->total_score = $score['total'];
            $log->hydration_score = $score['hydration'];
            $log->exercise_score = $score['exercise'];
            $log->save();

            // ADR-009 §3.1 — meal.daily_score_80_plus. Idempotency_key per
            // (uuid, date) gives once-per-day on the server even if the
            // score oscillates above/below the threshold during the day.
            if ($uuid !== '' && (int) $log->total_score >= self::DAILY_SCORE_THRESHOLD) {
                $this->gamification->publish(
                    $uuid,
                    'meal.daily_score_80_plus',
                    "meal.daily_score_80_plus.{$uuid}.{$date}",
                    ['score' => (int) $log->total_score],
                );
            }

            return [
                'date' => $date,
                'total_score' => (int) $log->total_score,
                'meals_logged' => (int) $log->meals_logged,
            ];
        });
    }
}
