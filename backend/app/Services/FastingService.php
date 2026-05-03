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

        // SPEC-progress-ritual-v1 PR #6 — fullscreen celebration on round
        // milestones (30/60/100/365). Idempotent via ritual idempotency_key.
        if (in_array($streak, [30, 60, 100, 365], true)) {
            app(\App\Services\Ritual\RitualDispatcher::class)->dispatch(
                $user,
                \App\Models\RitualEvent::KEY_STREAK_MILESTONE,
                "fasting_streak:{$user->id}:{$streak}",
                ['streak_kind' => 'fasting', 'streak_count' => $streak],
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
     * SPEC-fasting-redesign-v2 — now also returns the eating-window state
     * when the most recent session has ended (so the UI can countdown to
     * next fasting start instead of going blank).
     *
     * @return array<string,mixed>|null
     */
    public function snapshot(User $user): ?array
    {
        $active = $this->current($user);
        if ($active !== null) {
            return $this->fastingSnapshotFor($active);
        }
        // No active fasting — surface eating window if last session ended recently
        return $this->eatingWindowSnapshotFor($user);
    }

    /**
     * @return array<string,mixed>
     */
    private function fastingSnapshotFor(FastingSession $session): array
    {
        $now = CarbonImmutable::now();
        $startedAt = CarbonImmutable::parse($session->started_at);
        // Carbon's diffInMinutes returns float — frontend expects integer
        // minutes for display.
        $elapsedMinutes = (int) max(0, floor((float) $startedAt->diffInMinutes($now)));
        $target = (int) $session->target_duration_minutes;
        $progress = $target > 0 ? min(1.0, $elapsedMinutes / $target) : 0.0;

        return [
            'kind' => 'fasting',
            'id' => $session->id,
            'mode' => $session->mode,
            'started_at' => $startedAt->toIso8601String(),
            'target_duration_minutes' => $target,
            'elapsed_minutes' => $elapsedMinutes,
            'progress' => round($progress, 4),
            'eligible_to_eat_at' => $startedAt->addMinutes($target)->toIso8601String(),
            'phase' => $this->phaseFor($elapsedMinutes),
            'last_pushed_phase' => $session->last_pushed_phase,
        ];
    }

    /**
     * SPEC-v2 §2.4 — eating window after a session ends. The window length
     * is `total_cycle - target` minutes (e.g. 16:8 → 8h eating window).
     *
     * Returns null if no recent session OR if the eating window expired
     * (>2h after window end, we don't surface stale state).
     *
     * @return array<string,mixed>|null
     */
    private function eatingWindowSnapshotFor(User $user): ?array
    {
        $latest = FastingSession::query()
            ->where('user_id', $user->id)
            ->whereNotNull('ended_at')
            ->orderByDesc('ended_at')
            ->first();
        if ($latest === null || $latest->ended_at === null) {
            return null;
        }

        $endedAt = CarbonImmutable::parse($latest->ended_at);
        $eatingWindowMinutes = $this->eatingWindowMinutesFor((string) $latest->mode, (int) $latest->target_duration_minutes);
        $eatingEnd = $endedAt->addMinutes($eatingWindowMinutes);
        $now = CarbonImmutable::now();

        // Don't show stale state — if eating window finished >2h ago, return
        // null (UI shows the "start a new session" picker).
        if ($now->greaterThan($eatingEnd->addHours(2))) {
            return null;
        }

        $elapsedMinutes = (int) max(0, floor((float) $endedAt->diffInMinutes($now)));
        $remainingMinutes = max(0, $eatingWindowMinutes - $elapsedMinutes);
        $progress = $eatingWindowMinutes > 0 ? min(1.0, $elapsedMinutes / $eatingWindowMinutes) : 0.0;

        return [
            'kind' => 'eating_window',
            'last_session_id' => $latest->id,
            'mode' => $latest->mode,
            'eating_started_at' => $endedAt->toIso8601String(),
            'eating_ends_at' => $eatingEnd->toIso8601String(),
            'eating_window_minutes' => $eatingWindowMinutes,
            'elapsed_minutes' => $elapsedMinutes,
            'remaining_minutes' => $remainingMinutes,
            'progress' => round($progress, 4),
            'expired' => $now->greaterThanOrEqualTo($eatingEnd),
        ];
    }

    /**
     * Eating window length for a given fasting mode (24h cycle minus fasting).
     */
    public function eatingWindowMinutesFor(string $mode, int $targetMinutes): int
    {
        // Most modes are 24h cycles — eating window = 24*60 - target.
        // Custom modes might exceed 24h (we cap at 4h minimum eating).
        $eating = (24 * 60) - $targetMinutes;
        return max(4 * 60, $eating);
    }

    /**
     * SPEC-v2 §2.5 — let the user retroactively change when the fasting
     * started (the #1 user complaint: "I forgot to hit start"). Limited to
     * the last 24 hours.
     */
    public function markStartedAt(User $user, CarbonImmutable $newStart): FastingSession
    {
        $session = $this->current($user);
        if ($session === null) {
            throw new RuntimeException('fasting_no_active');
        }
        $now = CarbonImmutable::now();
        if ($newStart->greaterThan($now)) {
            throw new RuntimeException('fasting_start_in_future');
        }
        if ($newStart->lessThan($now->subHours(24))) {
            throw new RuntimeException('fasting_start_too_old');
        }

        $session->started_at = Carbon::instance($newStart->toDateTime());
        // Reset stage-push tracking so any newly-passed thresholds re-fire.
        $session->last_pushed_phase = null;
        $session->save();

        return $session->fresh();
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

    public const PHASE_ORDER = [
        'digesting',
        'settling',
        'glycogen_switch',
        'fat_burning',
        'autophagy',
        'deep_fast',
    ];

    /**
     * SPEC-v2 §2.3 — copy for stage-transition push templates.
     * Returns ['title' => ..., 'body' => ...] in 朵朵 voice.
     *
     * @return array{title:string, body:string}
     */
    public function stagePushCopy(string $phase): array
    {
        return match ($phase) {
            'settling' => [
                'title' => '進入空腹 🌱',
                'body' => '身體開始休息，朵朵陪妳繼續',
            ],
            'glycogen_switch' => [
                'title' => '能量切換中 ✨',
                'body' => '肝醣轉換，撐住，朵朵看見妳了',
            ],
            'fat_burning' => [
                'title' => '進入脂肪燃燒區 🔥',
                'body' => '身體開始燒脂肪，記得補水',
            ],
            'autophagy' => [
                'title' => '細胞清潔模式 🌟',
                'body' => '自噬作用啟動，妳的努力很值得',
            ],
            'deep_fast' => [
                'title' => '深度斷食 💪',
                'body' => '妳真的很有毅力，補水休息別忘了',
            ],
            default => [
                'title' => '繼續加油 🌷',
                'body' => '朵朵在這裡',
            ],
        };
    }
}
