<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Subscription\SubscriptionStateMachine;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Daily safety-net sweep for subscriptions whose lifecycle webhook never arrived.
 *
 * Apple / Google normally push DID_FAIL_TO_RENEW / GRACE_PERIOD_EXPIRED events
 * via {@see \App\Http\Controllers\Api\AppleNotificationController} +
 * {@see \App\Http\Controllers\Api\GooglePubSubController} which drive the state
 * machine in real time. But if a webhook is dropped, partitioned, or replayed
 * out of order the row would stay stuck in `active`/`grace` past its expiry.
 *
 * This command catches both cases:
 *   - state=active AND current_period_end < now-{grace}  → expire
 *   - state=grace  AND grace_until        < now          → expire
 *
 * Conservative on `active`: we wait an extra `--grace-hours` (default 6h) past
 * `current_period_end` before forcing expiry, so a slow webhook still gets to
 * win. Apple's GRACE_PERIOD_EXPIRED arrives within ~1h normally.
 *
 * Idempotent + safe to run more than daily (subsequent runs are no-ops once
 * states are correct).
 *
 * Schedule registered in routes/console.php — `daily()` at 03:30 UTC = 11:30
 * Asia/Taipei (off-peak).
 */
class SubscriptionLifecycleSweep extends Command
{
    protected $signature = 'subscription:lifecycle-sweep
        {--grace-hours=6 : Hours past current_period_end before forcing expiry on active rows}
        {--dry-run : List rows that would expire without writing}';

    protected $description = 'Safety-net sweep — expires Subscription rows whose webhook lifecycle was missed';

    public function handle(SubscriptionStateMachine $machine): int
    {
        $graceHours = max(0, (int) $this->option('grace-hours'));
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();
        $activeCutoff = $now->copy()->subHours($graceHours);

        $activeStuck = Subscription::query()
            ->where('state', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', $activeCutoff)
            ->get();

        $graceStuck = Subscription::query()
            ->where('state', 'grace')
            ->whereNotNull('grace_until')
            ->where('grace_until', '<', $now)
            ->get();

        $this->line(sprintf(
            'sweep: active_stuck=%d (period_end < now-%dh) grace_stuck=%d',
            $activeStuck->count(),
            $graceHours,
            $graceStuck->count(),
        ));

        if ($dryRun) {
            foreach ($activeStuck as $s) {
                $this->line(sprintf('  [dry] active→expired sub_id=%d period_end=%s', $s->id, (string) $s->current_period_end));
            }
            foreach ($graceStuck as $s) {
                $this->line(sprintf('  [dry] grace→expired sub_id=%d grace_until=%s', $s->id, (string) $s->grace_until));
            }

            return self::SUCCESS;
        }

        $expired = 0;
        foreach ($activeStuck->concat($graceStuck) as $sub) {
            try {
                $machine->expire($sub);
                $expired++;
            } catch (\Throwable $e) {
                $this->error(sprintf('failed to expire sub_id=%d: %s', $sub->id, $e->getMessage()));
            }
        }

        $this->info(sprintf('expired %d row(s)', $expired));

        return self::SUCCESS;
    }
}
