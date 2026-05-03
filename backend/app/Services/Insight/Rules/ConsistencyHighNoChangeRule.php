<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #11 — consistency_high_no_weight_change.
 * meals ≥6/7 + steps target ≥5/7 + weight 4w stable ±0.2kg.
 */
class ConsistencyHighNoChangeRule extends InsightRule
{
    public const KEY = 'consistency_high_no_change';

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        if ($snapshot->mealsDaysLogged7d() < 6) {
            return null;
        }
        if ($snapshot->stepsDaysMetTarget7d() < 5) {
            return null;
        }
        $delta4w = $snapshot->weight4wMaxDeltaKg();
        if ($delta4w === null || $delta4w > 0.2) {
            return null;
        }

        return new InsightResult(
            headline: '妳很努力但體重沒動 💭',
            body: '飲食、步數都很穩，可能是熱量缺口算太保守。'
                .'要不要重算 TDEE、或試試 16:8 斷食看看？',
            detectionPayload: [
                'meals_days_7d' => $snapshot->mealsDaysLogged7d(),
                'steps_days_met_7d' => $snapshot->stepsDaysMetTarget7d(),
                'weight_max_delta_4w_kg' => $delta4w,
            ],
            actionSuggestions: [
                ['label' => '重算 TDEE', 'action_key' => 'tdee_recompute'],
                ['label' => '試 16:8', 'action_key' => 'fasting_start_168'],
            ],
        );
    }
}
