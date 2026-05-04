<?php

namespace App\Services\Dodo\Streak;

use App\Models\User;
use App\Models\UserDailyStreak;
use App\Services\Gamification\GamificationPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SPEC-daily-login-streak — per-App 每日連續登入 streak service.
 *
 * `recordLogin()` 由 RecordDailyStreak middleware 在每個 authenticated
 * request 跑一次（成本：1 SELECT + 0~1 UPDATE）。
 *
 * 邏輯（時區 Asia/Taipei）：
 *   - last_login_date == today      → no-op；is_first_today=false
 *   - last_login_date == yesterday  → current_streak += 1; is_first_today=true
 *   - last_login_date is null / older → reset current_streak=1; is_first_today=true
 *
 * Milestone 觸發條件：is_first_today=true 且新 current_streak ∈
 *   [1, 3, 7, 14, 21, 30, 60, 100]
 *
 * Gamification publish：is_first_today=true 時，依 streak 是否打到
 * publisher catalog 已知的 meal.streak_3/7/14/30 觸發；catalog 沒有的
 * milestone（1 / 21 / 60 / 100）目前 fail-soft 不發（避免 422）。
 */
class DailyLoginStreakService
{
    private const TIMEZONE = 'Asia/Taipei';

    /** @var list<int> */
    private const MILESTONES = [1, 3, 7, 14, 21, 30, 60, 100];

    /**
     * Catalog-known event_kinds (see GamificationPublisher::KNOWN_EVENT_KINDS).
     * Only these get published — others fail-soft skip publish so the local
     * streak still works in environments where py-service catalog is behind.
     *
     * @var array<int, string>
     */
    private const PUBLISH_KIND_BY_STREAK = [
        3 => 'meal.streak_3',
        7 => 'meal.streak_7',
        14 => 'meal.streak_14',
        30 => 'meal.streak_30',
    ];

    public function __construct(
        private readonly GamificationPublisher $gamification,
    ) {}

    /**
     * @return array{
     *     streak: int,
     *     longest_streak: int,
     *     is_first_today: bool,
     *     is_milestone: bool,
     *     milestone_label: ?string,
     *     today_date: string,
     * }
     */
    public function recordLogin(User $user): array
    {
        $today = Carbon::now(self::TIMEZONE)->toDateString();
        $yesterday = Carbon::now(self::TIMEZONE)->subDay()->toDateString();

        $result = DB::transaction(function () use ($user, $today, $yesterday): array {
            /** @var UserDailyStreak $row */
            $row = UserDailyStreak::query()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['user_id' => $user->id],
                    ['current_streak' => 0, 'longest_streak' => 0, 'last_login_date' => null],
                );

            $last = $row->last_login_date?->toDateString();

            if ($last === $today) {
                return [
                    'streak' => (int) $row->current_streak,
                    'longest' => (int) $row->longest_streak,
                    'is_first_today' => false,
                ];
            }

            if ($last === $yesterday) {
                $row->current_streak = ((int) $row->current_streak) + 1;
            } else {
                $row->current_streak = 1;
            }

            if ($row->current_streak > $row->longest_streak) {
                $row->longest_streak = $row->current_streak;
            }

            $row->last_login_date = Carbon::parse($today);
            $row->save();

            return [
                'streak' => (int) $row->current_streak,
                'longest' => (int) $row->longest_streak,
                'is_first_today' => true,
            ];
        });

        $isMilestone = $result['is_first_today'] && in_array($result['streak'], self::MILESTONES, true);
        $milestoneLabel = $isMilestone ? $this->milestoneLabel($result['streak']) : null;

        if ($result['is_first_today']) {
            $this->safePublish($user, $result['streak'], $today);
        }

        return [
            'streak' => $result['streak'],
            'longest_streak' => $result['longest'],
            'is_first_today' => $result['is_first_today'],
            'is_milestone' => $isMilestone,
            'milestone_label' => $milestoneLabel,
            'today_date' => $today,
        ];
    }

    /**
     * Read-only snapshot — used by GET /api/streak/today.
     *
     * @return array{
     *     current_streak: int,
     *     longest_streak: int,
     *     last_login_date: ?string,
     *     today_date: string,
     * }
     */
    public function snapshot(User $user): array
    {
        $today = Carbon::now(self::TIMEZONE)->toDateString();
        $row = UserDailyStreak::query()->where('user_id', $user->id)->first();

        return [
            'current_streak' => $row ? (int) $row->current_streak : 0,
            'longest_streak' => $row ? (int) $row->longest_streak : 0,
            'last_login_date' => $row?->last_login_date?->toDateString(),
            'today_date' => $today,
        ];
    }

    private function milestoneLabel(int $streak): string
    {
        return match ($streak) {
            1 => '第一天',
            3 => '連續 3 天',
            7 => '連續 7 天',
            14 => '連續 14 天',
            21 => '連續 21 天',
            30 => '連續 30 天',
            60 => '連續 60 天',
            100 => '連續 100 天',
            default => "連續 {$streak} 天",
        };
    }

    /**
     * Fail-soft publish — local streak must succeed even when py-service is
     * down or catalog is behind. We swallow exceptions and log; queue retries
     * inside Publisher handle transient failures already.
     */
    private function safePublish(User $user, int $streak, string $today): void
    {
        $kind = self::PUBLISH_KIND_BY_STREAK[$streak] ?? null;
        if ($kind === null) {
            return;
        }

        $uuid = (string) ($user->pandora_user_uuid ?? '');
        if ($uuid === '') {
            return;
        }

        try {
            $this->gamification->publish(
                $uuid,
                $kind,
                "{$kind}.{$uuid}.{$today}",
                ['streak_days' => $streak, 'source' => 'daily_login'],
            );
        } catch (Throwable $e) {
            Log::warning('[DailyLoginStreak] publish failed (soft)', [
                'user_id' => $user->id,
                'kind' => $kind,
                'streak' => $streak,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
