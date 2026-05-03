<?php

namespace App\Services\Insight;

use App\Services\Insight\Rules\FastingStreakWithStepsDropRule;
use App\Services\Insight\Rules\InsightRule;
use App\Services\Insight\Rules\SleepDeficitWithWeightStallRule;
use App\Services\Insight\Rules\StreakMilestone30Rule;
use App\Services\Insight\Rules\WeightPlateauRule;

/**
 * SPEC-cross-metric-insight-v1 PR #1 — rule discovery.
 *
 * PR #1 ships 4 core rules; PR #2 adds the remaining 8 from SPEC §2.
 * Order matters only for tiebreaking — InsightEngine evaluates each
 * independently, so registry order does not affect detection logic.
 */
class RuleRegistry
{
    /** @return array<int, InsightRule> */
    public function all(): array
    {
        return [
            new WeightPlateauRule,
            new SleepDeficitWithWeightStallRule,
            new FastingStreakWithStepsDropRule,
            new StreakMilestone30Rule,
        ];
    }
}
