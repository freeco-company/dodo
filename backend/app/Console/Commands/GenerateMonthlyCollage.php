<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ritual\MonthlyCollageGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SPEC-progress-ritual-v1 PR #2 — schedule entry.
 *
 *   php artisan progress:generate-monthly-collage [--month=YYYY-MM]
 *
 * Default month = previous calendar month (when run on the 1st at 03:00 it
 * targets the just-finished month). Fires per-eligible-user; idempotent
 * via MonthlyCollage::updateOrCreate.
 */
class GenerateMonthlyCollage extends Command
{
    protected $signature = 'progress:generate-monthly-collage {--month=}';

    protected $description = 'Generate monthly progress collage for eligible users (SPEC-progress-ritual-v1)';

    public function handle(MonthlyCollageGenerator $generator): int
    {
        $month = $this->option('month');
        $monthStart = $month
            ? CarbonImmutable::createFromFormat('Y-m', $month, 'Asia/Taipei')->startOfMonth()
            : CarbonImmutable::now('Asia/Taipei')->subMonth()->startOfMonth();

        $this->info("Generating collages for {$monthStart->format('Y-m')}...");

        $users = User::query()
            ->whereIn('subscription_type', ['monthly', 'yearly', 'vip'])
            ->orWhere('membership_tier', 'fp_lifetime')
            ->cursor();

        $generated = 0;
        $skipped = 0;
        foreach ($users as $user) {
            $collage = $generator->generateForUser($user, $monthStart);
            if ($collage) {
                $generated++;
            } else {
                $skipped++;
            }
        }

        $this->info("Done: {$generated} generated, {$skipped} skipped (insufficient snapshots).");

        return self::SUCCESS;
    }
}
