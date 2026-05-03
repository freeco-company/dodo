<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #1 — weight_plateau_detected.
 *
 * Triggers when the 7-day moving average sits within ±0.2kg of the prior
 * 7-day average AND the calorie variation across the same window is < 10%
 * (the user is being consistent — flat weight is real, not noise).
 *
 * 朵朵 voice：「不是停滯，是身體在適應」 — gentle, not panic.
 * Compliance: no 減重 / 減脂 / 燃脂 wording.
 */
class WeightPlateauRule extends InsightRule
{
    public const KEY = 'weight_plateau_detected';

    private const PLATEAU_BAND_KG = 0.2;
    private const STABLE_KCAL_SD_RATIO = 0.10;

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $now = $snapshot->weightAvg7d();
        $prev = $snapshot->weightAvgPrev7d();
        $kcalSdRatio = $snapshot->mealsKcalSdRatio7d();

        if ($now === null || $prev === null || $kcalSdRatio === null) {
            return null;
        }
        if (abs($now - $prev) > self::PLATEAU_BAND_KG) {
            return null;
        }
        if ($kcalSdRatio >= self::STABLE_KCAL_SD_RATIO) {
            return null;
        }

        return new InsightResult(
            headline: '妳的體重 5 天平台了 🌱',
            body: '不是停滯，是身體在適應。'
                ."這週飲食很規律（卡路里波動 ".round($kcalSdRatio * 100, 1)."%），"
                .'要不要試試斷食日加散步、或變動一下 macro 比例？',
            detectionPayload: [
                'avg_kg_7d' => round($now, 2),
                'avg_kg_prev_7d' => round($prev, 2),
                'delta_kg' => round($now - $prev, 2),
                'kcal_sd_ratio' => round($kcalSdRatio, 3),
            ],
            actionSuggestions: [
                ['label' => '試試 16:8 斷食', 'action_key' => 'fasting_start_168'],
                ['label' => '今天加 2000 步', 'action_key' => 'steps_add_2000'],
            ],
        );
    }
}
