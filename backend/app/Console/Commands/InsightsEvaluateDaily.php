<?php

namespace App\Console\Commands;

use App\Models\HealthMetric;
use App\Models\Meal;
use App\Models\User;
use App\Services\Insight\InsightEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SPEC-cross-metric-insight-v1 PR #6 — daily evaluation cron.
 *
 *   php artisan insights:evaluate-daily [--user=ID]
 *
 * Schedule: every day 08:00 Asia/Taipei (so push fanout in PR #4 follows).
 *
 * Active-user filter: ≥1 meal or health record in the past 7 days. New /
 * dormant users are skipped (rules would just return null anyway, but this
 * cuts the worker from O(all users) to O(active) ≈ 5-15% of total).
 */
class InsightsEvaluateDaily extends Command
{
    protected $signature = 'insights:evaluate-daily {--user=}';

    protected $description = 'Run InsightEngine for active users (SPEC-cross-metric-insight-v1)';

    public function handle(InsightEngine $engine): int
    {
        $now = CarbonImmutable::now('Asia/Taipei');

        $userId = $this->option('user');
        if ($userId !== null) {
            $user = User::find($userId);
            if (! $user) {
                $this->error("user {$userId} not found");

                return self::FAILURE;
            }
            $fired = $engine->evaluateAllForUser($user, $now);
            $this->info("user {$user->id}: ".count($fired).' insights fired');

            return self::SUCCESS;
        }

        $sevenDaysAgo = $now->subDays(7);
        $activeUserIds = collect()
            ->merge(Meal::query()->where('created_at', '>=', $sevenDaysAgo)->distinct()->pluck('user_id'))
            ->merge(HealthMetric::query()->where('recorded_at', '>=', $sevenDaysAgo)->distinct()->pluck('user_id'))
            ->unique();

        $this->info("Evaluating {$activeUserIds->count()} active users...");

        $fired = 0;
        foreach (User::whereIn('id', $activeUserIds)->cursor() as $user) {
            $insights = $engine->evaluateAllForUser($user, $now);
            $fired += count($insights);
        }

        $this->info("Done: {$fired} total insights fired across {$activeUserIds->count()} users.");

        return self::SUCCESS;
    }
}
