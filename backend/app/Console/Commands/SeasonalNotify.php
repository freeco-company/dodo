<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PushDispatcher;
use App\Services\SeasonalContentService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SPEC-06 §2.3 — daily 09:00 (Asia/Taipei) seasonal push fanout.
 *
 * Two paths in the same command (cheap to combine since both touch the
 * same user list):
 *   - **Release-day push**: any window whose start_md == today → fan out
 *     `seasonal_release` template
 *   - **Expiring push**: any window with days_remaining == 7 → fan out
 *     `seasonal_expiring_soon` template (one nudge, one week before close)
 *
 * Targets: users with at least one meal in the past 14 days (active set).
 */
class SeasonalNotify extends Command
{
    protected $signature = 'seasonal:notify';

    protected $description = 'Daily seasonal release / expiring push fanout (SPEC-06).';

    public function __construct(
        private readonly SeasonalContentService $seasonal,
        private readonly PushDispatcher $push,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now('Asia/Taipei');
        $today = $now->toDateString();

        $releasing = array_filter(
            $this->seasonal->activeAt($now),
            fn ($w) => $w['start'] === $today,
        );
        $expiring7 = array_filter(
            $this->seasonal->activeAt($now),
            fn ($w) => (int) $w['days_remaining'] === 7,
        );

        if ($releasing === [] && $expiring7 === []) {
            $this->info('seasonal:notify — nothing to send today');

            return self::SUCCESS;
        }

        $cutoff = $now->subDays(14)->toDateTimeString();
        $totalSent = 0;
        $totalSkipped = 0;

        User::query()
            ->whereHas('meals', fn ($q) => $q->where('created_at', '>=', $cutoff))
            ->chunkById(200, function ($users) use ($releasing, $expiring7, &$totalSent, &$totalSkipped) {
                foreach ($users as $user) {
                    foreach ($releasing as $w) {
                        $r = $this->push->seasonalRelease($user, (string) $w['label']);
                        $totalSent += $r['sent'];
                        $totalSkipped += $r['skipped'];
                    }
                    foreach ($expiring7 as $w) {
                        $r = $this->push->seasonalExpiringSoon($user, (string) $w['label'], (int) $w['days_remaining']);
                        $totalSent += $r['sent'];
                        $totalSkipped += $r['skipped'];
                    }
                }
            });

        $this->info("seasonal:notify — sent {$totalSent}, skipped {$totalSkipped}");

        return self::SUCCESS;
    }
}
