<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\User;
use Carbon\Carbon;

/**
 * Translated from ai-game/src/services/checkin.ts.
 * Anti-abuse caps + score recalculation + journey advance hooks.
 */
class CheckinService
{
    public const DAILY_WATER_CAP_ML = 5000;

    public const DAILY_EXERCISE_CAP_MIN = 300;

    public const DAILY_WATER_GOAL_ML = 2000;

    public const DAILY_EXERCISE_GOAL_MIN = 30;

    public function __construct(private readonly JourneyService $journey) {}

    private function getOrCreateDailyLog(User $user, ?string $date = null): DailyLog
    {
        $date ??= Carbon::today()->toDateString();
        // Phase D Wave 2: read by uuid; dual-write on create
        $existing = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', $date)
            ->first();
        if ($existing) {
            return $existing;
        }

        return DailyLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $date,
        ]);
    }

    private function recalcScore(User $user, DailyLog $log): array
    {
        $score = ScoringService::daily([
            'total_calories' => $log->total_calories,
            'daily_calorie_target' => $user->daily_calorie_target ?? 1800,
            'total_protein_g' => (float) $log->total_protein_g,
            'daily_protein_target_g' => $user->daily_protein_target_g ?? 80,
            'meals_logged' => $log->meals_logged,
            'exercise_minutes' => $log->exercise_minutes,
            'water_ml' => $log->water_ml,
        ]);
        $log->total_score = $score['total'];
        $log->hydration_score = $score['hydration'];
        $log->exercise_score = $score['exercise'];
        $log->save();

        return $score;
    }

    /** @return array{water_ml:int, total_score:int, capped:bool} */
    public function logWater(User $user, int $ml): array
    {
        if ($ml < 0 || $ml > 6000) {
            abort(422, 'INVALID_WATER');
        }
        $log = $this->getOrCreateDailyLog($user);
        $requested = $log->water_ml + $ml;
        $newTotal = min(self::DAILY_WATER_CAP_ML, $requested);
        $capped = $newTotal < $requested;
        $log->water_ml = $newTotal;
        $log->save();
        $score = $this->recalcScore($user, $log);

        if ($ml >= 500) {
            $this->journey->tryAdvance($user, 'water');
        }

        return [
            'water_ml' => (int) $log->water_ml,
            'total_score' => $score['total'],
            'capped' => $capped,
        ];
    }

    /** @return array{water_ml:int, total_score:int} */
    public function setWater(User $user, int $ml): array
    {
        if ($ml < 0 || $ml > self::DAILY_WATER_CAP_ML) {
            abort(422, 'INVALID_WATER');
        }
        $log = $this->getOrCreateDailyLog($user);
        $log->water_ml = $ml;
        $log->save();
        $score = $this->recalcScore($user, $log);

        return ['water_ml' => (int) $log->water_ml, 'total_score' => $score['total']];
    }

    /** @return array{exercise_minutes:int, total_score:int, capped:bool} */
    public function logExercise(User $user, int $minutes): array
    {
        if ($minutes < 0 || $minutes > 600) {
            abort(422, 'INVALID_EXERCISE');
        }
        $log = $this->getOrCreateDailyLog($user);
        $requested = $log->exercise_minutes + $minutes;
        $newTotal = min(self::DAILY_EXERCISE_CAP_MIN, $requested);
        $capped = $newTotal < $requested;
        $log->exercise_minutes = $newTotal;
        $log->save();
        $score = $this->recalcScore($user, $log);
        if ($minutes >= 15) {
            $this->journey->tryAdvance($user, 'exercise');
        }

        return [
            'exercise_minutes' => (int) $log->exercise_minutes,
            'total_score' => $score['total'],
            'capped' => $capped,
        ];
    }

    /** @return array{exercise_minutes:int, total_score:int} */
    public function setExercise(User $user, int $minutes): array
    {
        if ($minutes < 0 || $minutes > self::DAILY_EXERCISE_CAP_MIN) {
            abort(422, 'INVALID_EXERCISE');
        }
        $log = $this->getOrCreateDailyLog($user);
        $log->exercise_minutes = $minutes;
        $log->save();
        $score = $this->recalcScore($user, $log);

        return ['exercise_minutes' => (int) $log->exercise_minutes, 'total_score' => $score['total']];
    }

    /** @return array{weight_kg:float, xp_gained:int} */
    public function logWeight(User $user, float $weight): array
    {
        if ($weight < 20 || $weight > 300) {
            abort(422, 'INVALID_WEIGHT');
        }
        $log = $this->getOrCreateDailyLog($user);
        $wasLogged = $log->weight_kg !== null;
        $log->weight_kg = $weight;
        $log->save();

        $xp = $wasLogged ? 0 : GameXp::REWARDS['WEIGHT_LOGGED'];
        if ($xp > 0) {
            $user->xp = (int) $user->xp + $xp;
            $user->level = GameXp::levelForXp((int) $user->xp);
        }
        $user->current_weight_kg = $weight;
        $user->save();

        return ['weight_kg' => $weight, 'xp_gained' => $xp];
    }
}
