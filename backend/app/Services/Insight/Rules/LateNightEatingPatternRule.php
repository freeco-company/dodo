<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #7 — late_night_eating_pattern.
 * ≥4 meals logged after 21:00 in past 7 days.
 */
class LateNightEatingPatternRule extends InsightRule
{
    public const KEY = 'late_night_eating_pattern';

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $count = $snapshot->lateNightMealCount7d();
        if ($count === null || $count < 4) {
            return null;
        }

        return new InsightResult(
            headline: '最近晚餐越來越晚 🌃',
            body: '這週有 '.$count.' 餐在 21:00 後吃，對睡眠不太友善。'
                .'要不要試試提早 1 小時？',
            detectionPayload: ['late_night_count' => $count],
            actionSuggestions: [['label' => '加晚餐提醒', 'action_key' => 'reminder_dinner']],
        );
    }
}
