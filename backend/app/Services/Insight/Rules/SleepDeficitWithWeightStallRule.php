<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #4 — sleep_deficit_with_weight_stall.
 *
 * Triggers when 7-day avg sleep < 6h AND weight is plateau-or-rising.
 * 朵朵 voice：避免下醫療結論，用「可能跟壓力有關」而不是「皮質醇 → 失眠」。
 */
class SleepDeficitWithWeightStallRule extends InsightRule
{
    public const KEY = 'sleep_deficit_with_weight_stall';

    private const SLEEP_DEFICIT_MINUTES = 360; // 6h

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $sleep = $snapshot->sleepAvgMinutes7d();
        $now = $snapshot->weightAvg7d();
        $prev = $snapshot->weightAvgPrev7d();

        if ($sleep === null || $now === null || $prev === null) {
            return null;
        }
        if ($sleep >= self::SLEEP_DEFICIT_MINUTES) {
            return null;
        }
        // weight not dropping (plateau or rising)
        if ($now < $prev - 0.2) {
            return null;
        }

        return new InsightResult(
            headline: '妳這週睡眠少了一些 🌙',
            body: '平均 '.round($sleep / 60, 1).' 小時，平台期可能跟壓力有關。'
                .'要不要早 30 分鐘關螢幕、試試睡前不滑手機？',
            detectionPayload: [
                'avg_sleep_minutes_7d' => round($sleep, 1),
                'weight_avg_kg_7d' => round($now, 2),
                'weight_avg_kg_prev_7d' => round($prev, 2),
            ],
            actionSuggestions: [
                ['label' => '加睡眠提醒', 'action_key' => 'reminder_sleep'],
            ],
        );
    }
}
