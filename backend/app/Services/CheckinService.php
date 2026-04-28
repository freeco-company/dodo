<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\User;
use App\Services\Conversion\ConversionEventPublisher;
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

    /**
     * ADR-003 §2.3 — engagement.deep is fired exactly once when the user
     * first reaches a 7-day consecutive checkin streak (any DailyLog entry
     * counts as a checkin). Tracked via Cache flag to prevent double-fire.
     */
    public const ENGAGEMENT_STREAK_DAYS = 7;

    public function __construct(
        private readonly JourneyService $journey,
        private readonly ConversionEventPublisher $conversion,
    ) {}

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

        $log = DailyLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $date,
        ]);

        // ADR-003 §2.3 — fire engagement.deep when this new daily log brings
        // the user to a 7-day consecutive streak (first time only, idempotent
        // via Cache flag on pandora_user_uuid).
        $this->maybeFireEngagementDeep($user);

        return $log;
    }

    /**
     * Compute the user's current consecutive-day checkin streak from DailyLog
     * and fire engagement.deep exactly once when it reaches the threshold.
     *
     * Streak = count of consecutive past days (including today) that have a
     * DailyLog row. We only need to check the last N days (cheap).
     */
    private function maybeFireEngagementDeep(User $user): void
    {
        $uuid = $user->pandora_user_uuid;
        if (! is_string($uuid) || $uuid === '') {
            return;
        }

        $cacheKey = "conversion:engagement_deep_fired:{$uuid}";
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return;
        }

        // Pull last ENGAGEMENT_STREAK_DAYS dates with logs and check contiguity.
        $today = Carbon::today();
        $since = $today->copy()->subDays(self::ENGAGEMENT_STREAK_DAYS - 1);
        // Use whereDate to side-step DATE-vs-DATETIME storage quirks
        // (SQLite stores as TEXT 'YYYY-MM-DD HH:MM:SS' so a plain BETWEEN
        // string compare can drop today's row).
        $dates = DailyLog::where('pandora_user_uuid', $uuid)
            ->whereDate('date', '>=', $since->toDateString())
            ->whereDate('date', '<=', $today->toDateString())
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->sort()
            ->values();

        if ($dates->count() < self::ENGAGEMENT_STREAK_DAYS) {
            return;
        }

        // Verify no gaps
        $expected = $since->copy();
        foreach ($dates as $date) {
            if ($date !== $expected->toDateString()) {
                return;
            }
            $expected->addDay();
        }

        $this->conversion->publish($uuid, 'engagement.deep', [
            'streak_days' => self::ENGAGEMENT_STREAK_DAYS,
            'reason' => 'consecutive_checkin',
        ]);
        // Mark fired for 30 days (re-eligible after a break — conservative
        // not to spam py-service if a user maintains a long streak).
        \Illuminate\Support\Facades\Cache::put($cacheKey, true, now()->addDays(30));
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
