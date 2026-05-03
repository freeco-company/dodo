<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #5 — fasting_streak_with_steps_drop.
 *
 * Triggers when fasting streak ≥ 7 days AND 7-day step total is ≥ 30%
 * lower than prior 7 days. 朵朵 voice：肯定斷食的努力，再 nudge 活動。
 */
class FastingStreakWithStepsDropRule extends InsightRule
{
    public const KEY = 'fasting_streak_with_steps_drop';

    private const FASTING_STREAK_FLOOR = 7;
    private const STEPS_DROP_RATIO = 0.30;

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $streak = $snapshot->fastingStreakDays();
        $now = $snapshot->steps7d();
        $prev = $snapshot->stepsPrev7d();

        if ($streak === null || $now === null || $prev === null) {
            return null;
        }
        if ($streak < self::FASTING_STREAK_FLOOR) {
            return null;
        }
        if ($prev <= 0) {
            return null;
        }
        $dropRatio = ($prev - $now) / $prev;
        if ($dropRatio < self::STEPS_DROP_RATIO) {
            return null;
        }

        return new InsightResult(
            headline: '斷食穩了，但活動掉了一點 💭',
            body: '妳已經連續斷食 '.$streak.' 天了 ✨ 不過這週步數比上週少了 '
                .round($dropRatio * 100).'%。'
                .'要不要試試斷食日順便走 2000 步？',
            detectionPayload: [
                'fasting_streak_days' => $streak,
                'steps_7d' => $now,
                'steps_prev_7d' => $prev,
                'drop_ratio' => round($dropRatio, 3),
            ],
            actionSuggestions: [
                ['label' => '今天加 2000 步', 'action_key' => 'steps_add_2000'],
            ],
        );
    }
}
