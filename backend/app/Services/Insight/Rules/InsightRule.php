<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC-cross-metric-insight-v1 PR #1 — base contract.
 *
 * Each rule is a pure function over a UserDataSnapshot → InsightResult|null.
 * Detection is deterministic (no LLM); narrative wrapping (free template here,
 * AI dynamic in PR #3) sits on top. The cooldown is enforced by InsightEngine
 * via insights.idempotency_key; each rule just reports its preferred gap.
 */
abstract class InsightRule
{
    abstract public function key(): string;

    abstract public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult;

    public function cooldownDays(): int
    {
        return 7;
    }
}
