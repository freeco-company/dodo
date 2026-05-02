<?php

namespace App\Console\Commands;

use App\Services\HealthMetricsService;
use Illuminate\Console\Command;

/**
 * SPEC-healthkit-integration §5 retention — drop raw_payload from
 * health_metrics older than --days days (default 90). The aggregate
 * value/unit row stays so trend graphs continue to work.
 */
class HealthMetricsPrune extends Command
{
    protected $signature = 'health:prune {--days=90}';

    protected $description = 'Null-out health_metrics.raw_payload older than --days (default 90) — privacy retention.';

    public function __construct(
        private readonly HealthMetricsService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be >= 1');

            return self::INVALID;
        }

        $rows = $this->service->pruneRawOlderThan($days);
        $this->info("health:prune — wiped raw_payload on {$rows} rows older than {$days} days");

        return self::SUCCESS;
    }
}
