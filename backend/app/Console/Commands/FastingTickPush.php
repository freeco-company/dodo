<?php

namespace App\Console\Commands;

use App\Models\FastingSession;
use App\Services\FastingService;
use App\Services\PushDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * SPEC-fasting-redesign-v2 Phase C — every 15 min, scan active fasting
 * sessions and push a stage-transition notification when a user crosses
 * a new threshold (settling 4h, glycogen_switch 8h, fat_burning 12h,
 * autophagy 16h, deep_fast 20h+).
 *
 * Idempotency: each session.last_pushed_phase tracks the deepest stage
 * we've pushed for; we only push when phase deepens past that mark.
 */
class FastingTickPush extends Command
{
    protected $signature = 'fasting:tick-push';

    protected $description = 'SPEC-v2 §2.3 — push朵朵 stage-transition message when active sessions cross a phase boundary.';

    public function __construct(
        private readonly FastingService $service,
        private readonly PushDispatcher $push,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;
        $checked = 0;

        FastingSession::query()
            ->whereNull('ended_at')
            ->with('user')
            ->chunkById(200, function ($sessions) use ($now, &$sent, &$checked) {
                foreach ($sessions as $session) {
                    $checked++;
                    if ($session->user === null) {
                        continue;
                    }
                    $startedAt = CarbonImmutable::parse($session->started_at);
                    $elapsed = (int) max(0, floor((float) $startedAt->diffInMinutes($now)));
                    $phase = $this->service->phaseFor($elapsed);

                    // Only push transitions, not the initial 'digesting' state.
                    if ($phase === 'digesting') {
                        continue;
                    }
                    $lastPushed = (string) ($session->last_pushed_phase ?? '');
                    $currentIdx = array_search($phase, FastingService::PHASE_ORDER, true);
                    $lastIdx = $lastPushed === '' ? -1 : array_search($lastPushed, FastingService::PHASE_ORDER, true);
                    if ($currentIdx === false || $lastIdx === false || $currentIdx <= $lastIdx) {
                        continue;
                    }

                    $copy = $this->service->stagePushCopy($phase);
                    /** @var \App\Models\User $u */
                    $u = $session->user;
                    $result = $this->push->custom(
                        $u,
                        $copy['title'],
                        $copy['body'],
                        "fasting_stage_{$phase}",
                        ['deep_link' => '/fasting'],
                    );
                    $sent += $result['sent'];

                    $session->last_pushed_phase = $phase;
                    $session->save();
                }
            });

        $this->info("fasting:tick-push — checked {$checked} active sessions, sent {$sent} stage-transition pushes");

        return self::SUCCESS;
    }
}
