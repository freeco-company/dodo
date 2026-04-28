<?php

namespace App\Services;

use App\Models\DailyLog;
use App\Models\User;
use App\Services\Conversion\ConversionEventPublisher;
use App\Services\Gamification\AchievementPublisher;
use App\Services\Gamification\GamificationPublisher;
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

    /**
     * ADR-009 §3 / catalog §3.1 — gamification streak milestones.
     * Fire once per cycle per threshold (idempotency_key on py-service is keyed by date,
     * so a fresh cycle on a different date triggers a new ledger entry).
     *
     * @var list<int>
     */
    public const GAMIFICATION_STREAK_THRESHOLDS = [3, 7, 14, 30];

    public function __construct(
        private readonly JourneyService $journey,
        private readonly ConversionEventPublisher $conversion,
        private readonly GamificationPublisher $gamification,
        private readonly AchievementPublisher $achievements,
    ) {}

    /**
     * Streak threshold → achievement code map. Only thresholds that have a
     * matching achievement in py-service ACHIEVEMENT_CATALOG appear; others
     * are XP-only milestones (handled by streak_3 / streak_14).
     *
     * @var array<int, string>
     */
    private const STREAK_THRESHOLD_TO_ACHIEVEMENT = [
        7 => 'dodo.streak_7',
        30 => 'dodo.streak_30',
    ];

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

        // ADR-009 §3 / catalog §3.1 — fire gamification streak milestones.
        $this->maybeFireGamificationStreaks($user);

        return $log;
    }

    /**
     * Compute streak count for a window ending at `endDate` (inclusive). 0 if
     * `endDate` itself has no DailyLog row. Counts days back as long as they
     * are contiguous and have a row.
     */
    private function computeStreak(string $uuid, Carbon $endDate, int $maxLookback = 35): int
    {
        $since = $endDate->copy()->subDays($maxLookback - 1);
        $dates = DailyLog::where('pandora_user_uuid', $uuid)
            ->whereDate('date', '>=', $since->toDateString())
            ->whereDate('date', '<=', $endDate->toDateString())
            ->pluck('date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();

        $set = array_flip($dates);
        $count = 0;
        $cursor = $endDate->copy();
        while (isset($set[$cursor->toDateString()])) {
            $count++;
            $cursor->subDay();
        }

        return $count;
    }

    /**
     * Fire `dodo.streak_{N}` events for thresholds the user crossed *today*
     * (i.e., today's streak >= N AND yesterday's streak < N). idempotency_key
     * is per-(uuid, threshold, today) so naturally idempotent across retries
     * and across multiple checkin writes within the same day.
     */
    private function maybeFireGamificationStreaks(User $user): void
    {
        $uuid = $user->pandora_user_uuid;
        if (! is_string($uuid) || $uuid === '') {
            return;
        }

        $today = Carbon::today();
        $todayCount = $this->computeStreak($uuid, $today);
        if ($todayCount === 0) {
            return;
        }
        $yesterdayCount = $this->computeStreak($uuid, $today->copy()->subDay());

        foreach (self::GAMIFICATION_STREAK_THRESHOLDS as $threshold) {
            if ($todayCount >= $threshold && $yesterdayCount < $threshold) {
                $this->gamification->publish(
                    $uuid,
                    "dodo.streak_{$threshold}",
                    "dodo.streak_{$threshold}.{$uuid}.{$today->toDateString()}",
                    ['streak_days' => $todayCount],
                );

                // ADR-009 §5 — fire achievement award alongside the XP event for
                // thresholds that have a matching badge. Server idempotent on
                // (uuid, code) so repeat fires are harmless.
                $achCode = self::STREAK_THRESHOLD_TO_ACHIEVEMENT[$threshold] ?? null;
                if ($achCode !== null) {
                    $this->achievements->publish(
                        $uuid,
                        $achCode,
                        "{$achCode}.{$uuid}",
                        ['streak_days' => $todayCount, 'reached_at' => $today->toDateString()],
                    );
                }
            }
        }
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

        // ADR-009 §3 / catalog §3.1 — fire dodo.weight_logged once per day
        // (server daily_cap_xp=5 also enforces; we additionally gate locally
        // on `wasLogged` to keep idempotency_key clean per-day).
        $uuid = is_string($user->pandora_user_uuid) ? $user->pandora_user_uuid : '';
        if ($uuid !== '' && ! $wasLogged) {
            $today = $log->date instanceof \Carbon\CarbonInterface
                ? $log->date->toDateString()
                : Carbon::parse((string) $log->date)->toDateString();
            $this->gamification->publish(
                $uuid,
                'dodo.weight_logged',
                "dodo.weight_logged.{$uuid}.{$today}",
                ['weight_kg' => $weight],
            );
        }

        return ['weight_kg' => $weight, 'xp_gained' => $xp];
    }
}
