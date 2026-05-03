<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Insight\InsightEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * SPEC-cross-metric-insight-v1 PR #6 — realtime evaluate for a single user.
 *
 * Dispatched after meal POST / health metric sync / weight log so milestone
 * insights (streak_30 etc.) fire same-day rather than waiting for the cron.
 *
 * Idempotent at the engine level (cooldown + idempotency_key), so calling
 * this multiple times in one day is safe — only fires new insights.
 */
class EvaluateInsightsForUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public int $userId) {}

    public function handle(InsightEngine $engine): void
    {
        $user = User::find($this->userId);
        if ($user === null) {
            return;
        }
        $engine->evaluateAllForUser($user);
    }
}
