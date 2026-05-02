<?php

namespace App\Services;

use App\Models\HealthMetric;
use App\Models\User;
use App\Services\Gamification\GamificationPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * SPEC-healthkit-integration Phase 1 — service for HealthKit / Health
 * Connect metric ingestion.
 *
 * Design notes:
 * - sync() is upsert-by-(user, type, recorded_at). Same metric re-synced =
 *   value updated (weight scale corrections, late-arriving HK refresh).
 * - Free vs paid type allowlist enforced here so the UI can pre-filter and
 *   the API can defensively reject paid types submitted by free users.
 * - We never pull from device — only accept upload. PII surface stays
 *   minimal and Apple/Google's privacy expectations are honored.
 * - Steps goal achievement (>=6000) → gamification publish once per day.
 */
class HealthMetricsService
{
    public const TYPE_STEPS = 'steps';
    public const TYPE_ACTIVE_KCAL = 'active_kcal';
    public const TYPE_WEIGHT = 'weight';
    public const TYPE_WORKOUT = 'workout';
    public const TYPE_SLEEP_MINUTES = 'sleep_minutes';
    public const TYPE_HEART_RATE = 'heart_rate';

    public const FREE_TYPES = [
        self::TYPE_STEPS,
        self::TYPE_ACTIVE_KCAL,
        self::TYPE_WEIGHT,
        self::TYPE_WORKOUT,
    ];

    public const PAID_TYPES = [
        self::TYPE_SLEEP_MINUTES,
        self::TYPE_HEART_RATE,
    ];

    public const ALL_TYPES = [
        self::TYPE_STEPS,
        self::TYPE_ACTIVE_KCAL,
        self::TYPE_WEIGHT,
        self::TYPE_WORKOUT,
        self::TYPE_SLEEP_MINUTES,
        self::TYPE_HEART_RATE,
    ];

    public const FREE_HISTORY_DAYS = 7;
    public const STEPS_GOAL = 6000;

    public function __construct(
        private readonly EntitlementsService $entitlements,
        private readonly GamificationPublisher $gamification,
    ) {}

    /**
     * Ingest a batch of metrics. Returns counts.
     *
     * @param  list<array{type:string,value:float,unit:string,recorded_at:string,source?:string,raw_payload?:array<string,mixed>}>  $batch
     * @return array{accepted:int,rejected:int,reasons:array<string,int>}
     */
    public function sync(User $user, array $batch): array
    {
        $isPaid = $this->entitlements->isPaid($user);
        $accepted = 0;
        $rejected = 0;
        $reasons = [];

        foreach ($batch as $row) {
            $type = $row['type'];
            if (! in_array($type, self::ALL_TYPES, true)) {
                $rejected++;
                $reasons['unknown_type'] = ($reasons['unknown_type'] ?? 0) + 1;
                continue;
            }
            if (in_array($type, self::PAID_TYPES, true) && ! $isPaid) {
                $rejected++;
                $reasons['paid_type_for_free_user'] = ($reasons['paid_type_for_free_user'] ?? 0) + 1;
                continue;
            }

            $recordedAt = CarbonImmutable::parse($row['recorded_at']);
            HealthMetric::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'type' => $type,
                    'recorded_at' => Carbon::instance($recordedAt->toDateTime()),
                ],
                [
                    'value' => (float) $row['value'],
                    'unit' => (string) $row['unit'],
                    'source' => (string) ($row['source'] ?? 'healthkit'),
                    'raw_payload' => $row['raw_payload'] ?? null,
                ],
            );
            $accepted++;

            // Steps goal achievement (per-day idempotent)
            if ($type === self::TYPE_STEPS && (float) $row['value'] >= self::STEPS_GOAL) {
                $this->maybePublishStepsGoal($user, $recordedAt, (int) $row['value']);
            }
            if ($type === self::TYPE_WEIGHT) {
                $this->maybePublishWeight($user, $recordedAt, (float) $row['value']);
            }
        }

        return [
            'accepted' => $accepted,
            'rejected' => $rejected,
            'reasons' => $reasons,
        ];
    }

    /**
     * Snapshot for the Today widget.
     *
     * @return array<string,mixed>
     */
    public function today(User $user): array
    {
        $tz = 'Asia/Taipei';
        $today = CarbonImmutable::now($tz)->toDateString();
        $yesterday = CarbonImmutable::now($tz)->subDay()->toDateString();

        $todayMetrics = HealthMetric::query()
            ->where('user_id', $user->id)
            ->whereBetween('recorded_at', [$today.' 00:00:00', $today.' 23:59:59'])
            ->get()
            ->keyBy('type');

        // Latest weight may be from any past day
        $latestWeight = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', self::TYPE_WEIGHT)
            ->orderByDesc('recorded_at')
            ->first();

        $isPaid = $this->entitlements->isPaid($user);

        return [
            'date' => $today,
            'steps' => isset($todayMetrics[self::TYPE_STEPS])
                ? (int) $todayMetrics[self::TYPE_STEPS]->value : null,
            'steps_goal' => self::STEPS_GOAL,
            'active_kcal' => isset($todayMetrics[self::TYPE_ACTIVE_KCAL])
                ? (int) $todayMetrics[self::TYPE_ACTIVE_KCAL]->value : null,
            'workouts' => isset($todayMetrics[self::TYPE_WORKOUT])
                ? (int) $todayMetrics[self::TYPE_WORKOUT]->value : 0,
            'weight_kg' => $latestWeight ? round((float) $latestWeight->value, 1) : null,
            'weight_recorded_at' => $latestWeight?->recorded_at?->toIso8601String(),
            'sleep_minutes' => $isPaid && isset($todayMetrics[self::TYPE_SLEEP_MINUTES])
                ? (int) $todayMetrics[self::TYPE_SLEEP_MINUTES]->value : null,
            'sleep_locked' => ! $isPaid,
            'heart_rate' => $isPaid && isset($todayMetrics[self::TYPE_HEART_RATE])
                ? (int) $todayMetrics[self::TYPE_HEART_RATE]->value : null,
            'has_any_data' => $todayMetrics->isNotEmpty() || $latestWeight !== null,
        ];
    }

    /**
     * History for a single type (paginated, tier-gated to 7 days for free).
     *
     * @return array{data: list<array<string,mixed>>, history_capped_days: ?int}
     */
    public function history(User $user, string $type, int $days = 30): array
    {
        if (! in_array($type, self::ALL_TYPES, true)) {
            return ['data' => [], 'history_capped_days' => null];
        }

        $isPaid = $this->entitlements->isPaid($user);
        $cap = $isPaid ? null : self::FREE_HISTORY_DAYS;
        $effectiveDays = $cap !== null ? min($days, $cap) : $days;
        $cutoff = CarbonImmutable::now()->subDays($effectiveDays);

        $rows = HealthMetric::query()
            ->where('user_id', $user->id)
            ->where('type', $type)
            ->where('recorded_at', '>=', $cutoff)
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'value', 'unit'])
            ->map(fn (HealthMetric $m) => [
                'recorded_at' => $m->recorded_at->toIso8601String(),
                'value' => round((float) $m->value, 2),
                'unit' => $m->unit,
            ])
            ->values()
            ->all();

        return [
            'data' => $rows,
            'history_capped_days' => $cap,
        ];
    }

    /**
     * SPEC §5 retention — drop raw_payload (not the row) on records >90 days.
     * Aggregate value/unit is preserved for trend graphs.
     */
    public function pruneRawOlderThan(int $days = 90): int
    {
        $cutoff = CarbonImmutable::now()->subDays($days);

        return HealthMetric::query()
            ->where('recorded_at', '<', $cutoff)
            ->whereNotNull('raw_payload')
            ->update(['raw_payload' => null]);
    }

    private function maybePublishStepsGoal(User $user, CarbonImmutable $recordedAt, int $stepCount): void
    {
        $uuid = $user->pandora_user_uuid;
        if ($uuid === null || $uuid === '') {
            return;
        }
        $dayKey = $recordedAt->setTimezone('Asia/Taipei')->toDateString();
        $this->gamification->publish(
            $uuid,
            'meal.steps_goal_achieved',
            "meal.steps_goal_achieved.{$uuid}.{$dayKey}",
            ['steps' => $stepCount, 'goal' => self::STEPS_GOAL],
            $recordedAt,
        );
    }

    private function maybePublishWeight(User $user, CarbonImmutable $recordedAt, float $kg): void
    {
        $uuid = $user->pandora_user_uuid;
        if ($uuid === null || $uuid === '') {
            return;
        }
        $dayKey = $recordedAt->setTimezone('Asia/Taipei')->toDateString();
        $this->gamification->publish(
            $uuid,
            'meal.weight_logged',
            "meal.weight_logged.{$uuid}.{$dayKey}",
            ['weight_kg' => round($kg, 2)],
            $recordedAt,
        );
    }
}
