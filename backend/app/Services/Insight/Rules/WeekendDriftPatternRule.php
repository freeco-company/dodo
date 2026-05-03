<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #10 — weekend_drift_pattern.
 * Weekend avg kcal ≥ weekday +30%.
 */
class WeekendDriftPatternRule extends InsightRule
{
    public const KEY = 'weekend_drift_pattern';

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $excess = $snapshot->weekendKcalExcessRatio();
        if ($excess === null || $excess < 0.30) {
            return null;
        }

        return new InsightResult(
            headline: '週末妳會放鬆一下 🌱',
            body: '週末平均比平日多 '.round($excess * 100).'%，沒關係，'
                .'但要不要試試半放鬆？目標放寬 10% 而不是無上限。',
            detectionPayload: ['weekend_excess_ratio' => round($excess, 2)],
            actionSuggestions: [['label' => '設週末彈性目標', 'action_key' => 'goal_weekend_flex']],
        );
    }
}
