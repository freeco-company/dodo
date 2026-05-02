<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\PushDispatcher;
use App\Services\WeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SPEC-04 §3 — Sunday 20:00 (Asia/Taipei) push「妳的本週報告 ✨」.
 *
 * Pre-condition: `reports:generate-weekly` runs at 19:00 to ensure each
 * active user has a fresh row. We then iterate users with a non-empty row
 * for the current week and send them the FCM template.
 */
class WeeklyReportNotify extends Command
{
    protected $signature = 'reports:notify-weekly';

    protected $description = 'Push「本週小報告出爐了」to active users on Sunday 20:00 (Asia/Taipei).';

    public function __construct(
        private readonly WeeklyReportService $service,
        private readonly PushDispatcher $push,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $weekStart = $this->service->weekStartFor(CarbonImmutable::now('Asia/Taipei'));
        $weekEnd = $weekStart->addDays(6);

        $sent = 0;
        $skipped = 0;
        WeeklyReport::query()
            ->where('week_start', $weekStart->toDateString())
            ->with('user')
            ->chunkById(200, function ($reports) use (&$sent, &$skipped, $weekEnd) {
                foreach ($reports as $report) {
                    /** @var ?User $user */
                    $user = $report->user;
                    if ($user === null) {
                        continue;
                    }
                    $result = $this->push->weeklyReportReady($user, $weekEnd->toDateString());
                    $sent += $result['sent'];
                    $skipped += $result['skipped'];
                }
            });

        $this->info("reports:notify-weekly — sent {$sent}, skipped {$skipped} for week {$weekStart->toDateString()}");

        return self::SUCCESS;
    }
}
