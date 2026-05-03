<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #3 — weight_dropping_too_fast.
 * 7d MA delta < -1.5kg + avg kcal < 1200 (健康 floor 警戒).
 *
 * 朵朵 voice: 提醒、不嚇人、不下醫療結論；推蛋白補充當行動。
 */
class WeightDroppingTooFastRule extends InsightRule
{
    public const KEY = 'weight_dropping_too_fast';

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $now = $snapshot->weightAvg7d();
        $prev = $snapshot->weightAvgPrev7d();
        $kcal = $snapshot->mealsKcalAvg7d();
        if ($now === null || $prev === null || $kcal === null) {
            return null;
        }
        if (($now - $prev) > -1.5) {
            return null;
        }
        if ($kcal >= 1200) {
            return null;
        }
        if ($snapshot->mealsDaysLogged7d() < 5) {
            return null;
        }

        return new InsightResult(
            headline: '妳這週掉得有點快 🌱',
            body: '可能是水分流失，記得補蛋白。'
                .'平均每天約 '.round($kcal).' kcal，可以再吃一點，身體會感謝妳。',
            detectionPayload: [
                'delta_kg' => round($now - $prev, 2),
                'avg_kcal' => round($kcal),
            ],
            actionSuggestions: [['label' => '提高蛋白目標', 'action_key' => 'goal_protein_up']],
        );
    }
}
