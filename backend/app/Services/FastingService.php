<?php

namespace App\Services;

use App\Models\FastingSession;
use App\Models\User;
use App\Services\Gamification\AchievementPublisher;
use App\Services\Gamification\GamificationPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * SPEC-fasting-timer Phase 1 — service layer for intermittent fasting timer.
 *
 * One active session per user (ended_at NULL) is enforced here, not at DB
 * level — keeping the table simple and letting us return clean 422 messages.
 */
class FastingService
{
    /**
     * Mode → target duration in minutes.
     * 5:2 is per-day style (not a single timer), so v1 does not implement it
     * as a session — it lives as a separate quest later.
     */
    public const MODE_DURATIONS = [
        '16:8' => 16 * 60,
        '14:10' => 14 * 60,
        '18:6' => 18 * 60,
        '20:4' => 20 * 60,
        'custom' => null, // caller must supply target_duration_minutes
    ];

    public const FREE_MODES = ['16:8', '14:10'];
    public const PAID_MODES = ['18:6', '20:4', 'custom'];

    public const FREE_HISTORY_DAYS = 7;

    public function __construct(
        private readonly EntitlementsService $entitlements,
        private readonly GamificationPublisher $gamification,
        private readonly AchievementPublisher $achievements,
        private readonly PushDispatcher $push,
    ) {}

    public function isModeAllowed(User $user, string $mode): bool
    {
        if (in_array($mode, self::FREE_MODES, true)) {
            return true;
        }
        if (! in_array($mode, self::PAID_MODES, true)) {
            return false;
        }

        return $this->entitlements->isPaid($user);
    }

    public function current(User $user): ?FastingSession
    {
        return FastingSession::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();
    }

    /**
     * Start a new fasting session. Throws if one is already active.
     *
     * @param  array{mode:string,target_duration_minutes?:int,started_at?:string}  $payload
     */
    public function start(User $user, array $payload): FastingSession
    {
        if ($this->current($user) !== null) {
            throw new RuntimeException('fasting_already_active');
        }

        $mode = $payload['mode'];
        if (! array_key_exists($mode, self::MODE_DURATIONS)) {
            throw new RuntimeException('fasting_mode_invalid');
        }
        if (! $this->isModeAllowed($user, $mode)) {
            throw new RuntimeException('fasting_mode_locked');
        }

        $target = self::MODE_DURATIONS[$mode]
            ?? (int) ($payload['target_duration_minutes'] ?? 0);
        if ($target < 13 * 60 || $target > 22 * 60) {
            throw new RuntimeException('fasting_target_out_of_range');
        }

        $startedAt = isset($payload['started_at'])
            ? CarbonImmutable::parse($payload['started_at'])
            : CarbonImmutable::now();

        return FastingSession::create([
            'user_id' => $user->id,
            'mode' => $mode,
            'target_duration_minutes' => $target,
            'started_at' => $startedAt,
            'source_app' => 'dodo',
        ]);
    }

    public function end(User $user, ?string $endedAtIso = null): FastingSession
    {
        $session = $this->current($user);
        if ($session === null) {
            throw new RuntimeException('fasting_no_active');
        }

        $endedAt = $endedAtIso !== null
            ? CarbonImmutable::parse($endedAtIso)
            : CarbonImmutable::now();
        $startedAt = CarbonImmutable::parse($session->started_at);

        if ($endedAt->lessThanOrEqualTo($startedAt)) {
            throw new RuntimeException('fasting_ended_before_started');
        }

        $elapsedMinutes = $startedAt->diffInMinutes($endedAt);
        $session->ended_at = Carbon::instance($endedAt->toDateTime());
        $session->completed = $elapsedMinutes >= $session->target_duration_minutes;
        $session->save();

        if ($session->completed) {
            $this->publishCompletion($user, $session, $endedAt);
            // Best-effort completion push — silent on failure (FCM not configured etc).
            try {
                $this->push->fastingCompleted($user, (string) $session->mode, (int) $elapsedMinutes);
            } catch (\Throwable $e) { /* fail-soft */ }
        }

        return $session->fresh();
    }

    /**
     * SPEC-fasting-timer Phase 2 §6.1 — gamification + achievement on completed.
     *
     * Two-tier:
     *   - every completed session → gamification XP via meal.fasting_completed
     *   - milestones (first / 7-streak / 30-streak) → AchievementPublisher
     *
     * Streak counts consecutive calendar days (Asia/Taipei) ending today with
     * at least one completed session — same definition we use elsewhere
     * (CheckinService) so users have one mental model.
     */
    private function publishCompletion(User $user, FastingSession $session, CarbonImmutable $endedAt): void
    {
        $uuid = $user->pandora_user_uuid;
        if ($uuid === null || $uuid === '') {
            return;
        }

        $occurredKey = $endedAt->getTimestamp();
        $this->gamification->publish(
            $uuid,
            'meal.fasting_completed',
            "meal.fasting_completed.{$session->id}",
            [
                'mode' => $session->mode,
                'target_minutes' => (int) $session->target_duration_minutes,
            ],
            $endedAt,
        );

        $totalCompleted = FastingSession::query()
            ->where('user_id', $user->id)
            ->where('completed', true)
            ->count();

        if ($totalCompleted === 1) {
            $this->achievements->publish(
                $uuid,
                'meal.fasting_first',
                "meal.fasting_first.{$uuid}",
                ['mode' => $session->mode],
                $endedAt,
            );
        }

        $streak = $this->currentDayStreak($user, $endedAt);
        if ($streak === 7) {
            $this->achievements->publish(
                $uuid,
                'meal.fasting_streak_7',
                "meal.fasting_streak_7.{$uuid}.{$occurredKey}",
                ['streak_days' => 7],
                $endedAt,
            );
            $this->gamification->publish(
                $uuid,
                'meal.fasting_streak_7',
                "meal.fasting_streak_7.{$uuid}." . $endedAt->toDateString(),
                ['streak_days' => 7],
                $endedAt,
            );
        }
        if ($streak === 30) {
            $this->achievements->publish(
                $uuid,
                'meal.fasting_streak_30',
                "meal.fasting_streak_30.{$uuid}.{$occurredKey}",
                ['streak_days' => 30],
                $endedAt,
            );
        }
    }

    /**
     * Consecutive-calendar-day streak (Asia/Taipei) ending on $now's date,
     * counting days that have at least one `completed` session.
     */
    public function currentDayStreak(User $user, CarbonImmutable $now): int
    {
        $tz = 'Asia/Taipei';
        $today = $now->setTimezone($tz)->toDateString();
        $daysWithCompletion = FastingSession::query()
            ->where('user_id', $user->id)
            ->where('completed', true)
            ->whereNotNull('ended_at')
            ->orderByDesc('ended_at')
            ->limit(60)
            ->get(['ended_at'])
            ->map(fn ($r) => CarbonImmutable::parse($r->ended_at)->setTimezone($tz)->toDateString())
            ->unique()
            ->values()
            ->all();
        $set = array_flip($daysWithCompletion);

        $cursor = CarbonImmutable::parse($today, $tz);
        if (! isset($set[$cursor->toDateString()])) {
            // grace: count from yesterday if today has nothing yet
            $cursor = $cursor->subDay();
            if (! isset($set[$cursor->toDateString()])) {
                return 0;
            }
        }

        $streak = 0;
        while (isset($set[$cursor->toDateString()])) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }

    /**
     * History — paginated. Free tier capped to last N days.
     *
     * @return LengthAwarePaginator<FastingSession>
     */
    public function history(User $user, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $query = FastingSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->orderByDesc('ended_at');

        if (! $this->entitlements->isPaid($user)) {
            $cutoff = CarbonImmutable::now()->subDays(self::FREE_HISTORY_DAYS);
            $query->where('ended_at', '>=', $cutoff);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Snapshot for `/api/fasting/current`, including derived progress fields.
     *
     * @return array<string,mixed>|null
     */
    public function snapshot(User $user): ?array
    {
        $session = $this->current($user);
        if ($session === null) {
            return null;
        }

        $now = CarbonImmutable::now();
        $startedAt = CarbonImmutable::parse($session->started_at);
        // Carbon's diffInMinutes returns float — frontend expects integer
        // minutes for display (otherwise 0.012m sub-second startup leaks
        // into the UI as "0.012307116666666668m").
        $elapsedMinutes = (int) max(0, floor((float) $startedAt->diffInMinutes($now)));
        $target = (int) $session->target_duration_minutes;
        $progress = $target > 0 ? min(1.0, $elapsedMinutes / $target) : 0.0;

        return [
            'id' => $session->id,
            'mode' => $session->mode,
            'started_at' => $startedAt->toIso8601String(),
            'target_duration_minutes' => $target,
            'elapsed_minutes' => $elapsedMinutes,
            'progress' => round($progress, 4),
            'eligible_to_eat_at' => $startedAt->addMinutes($target)->toIso8601String(),
            'phase' => $this->phaseFor($elapsedMinutes),
        ];
    }

    /**
     * SPEC §3.3 階段燈 — phase tag 給 frontend 對應 UI label / 朵朵 voice。
     * Score-only; copy 由 frontend / push template 負責。
     */
    public function phaseFor(int $elapsedMinutes): string
    {
        return match (true) {
            $elapsedMinutes < 4 * 60 => 'digesting',
            $elapsedMinutes < 8 * 60 => 'settling',
            $elapsedMinutes < 12 * 60 => 'glycogen_switch',
            $elapsedMinutes < 16 * 60 => 'fat_burning',
            $elapsedMinutes < 20 * 60 => 'autophagy',
            default => 'deep_fast',
        };
    }
}
