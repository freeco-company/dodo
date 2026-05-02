<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\WeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SPEC-weekly-ai-report Phase 1 — pre-generate the current week's report
 * for all active users on Sunday 19:00 (Asia/Taipei). Avoids users hitting
 * the API on Sunday 20:00 push notification and waiting for aggregation.
 *
 * "Active" = has at least one meal or HK metric in the past 14 days.
 */
class WeeklyReportGenerate extends Command
{
    protected $signature = 'reports:generate-weekly';

    protected $description = 'Pre-generate current-week WeeklyReport rows for active users (SPEC-04).';

    public function __construct(
        private readonly WeeklyReportService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $weekStart = $this->service->weekStartFor(CarbonImmutable::now('Asia/Taipei'));
        $cutoff = CarbonImmutable::now()->subDays(14)->toDateTimeString();

        $count = 0;
        User::query()
            ->whereHas('meals', fn ($q) => $q->where('created_at', '>=', $cutoff))
            ->orWhereHas('healthMetrics', fn ($q) => $q->where('recorded_at', '>=', $cutoff))
            ->chunkById(200, function ($users) use ($weekStart, &$count) {
                foreach ($users as $user) {
                    try {
                        $this->service->generate($user, $weekStart);
                        $count++;
                    } catch (\Throwable $e) {
                        $this->warn("user {$user->id} generate failed: {$e->getMessage()}");
                    }
                }
            });

        $this->info("reports:generate-weekly — generated {$count} reports for week {$weekStart->toDateString()}");

        return self::SUCCESS;
    }
}
