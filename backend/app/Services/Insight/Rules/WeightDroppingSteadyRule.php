<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #2 — weight_dropping_steady.
 * 7d MA -0.5 ~ -1.5kg + ≥5/7 days logged.
 */
class WeightDroppingSteadyRule extends InsightRule
{
    public const KEY = 'weight_dropping_steady';

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $now = $snapshot->weightAvg7d();
        $prev = $snapshot->weightAvgPrev7d();
        if ($now === null || $prev === null) {
            return null;
        }
        $delta = $now - $prev;
        if ($delta > -0.5 || $delta < -1.5) {
            return null;
        }
        if ($snapshot->mealsDaysLogged7d() < 5) {
            return null;
        }

        return new InsightResult(
            headline: '妳的節奏抓得超穩 ✨',
            body: '一週移動了 '.round(abs($delta), 1).'kg，剛剛好。'
                .'維持這個習慣，身體會適應得更好。',
            detectionPayload: ['delta_kg' => round($delta, 2), 'days_logged' => $snapshot->mealsDaysLogged7d()],
            actionSuggestions: [['label' => '看本週 insight', 'action_key' => 'insight_overview']],
        );
    }
}
