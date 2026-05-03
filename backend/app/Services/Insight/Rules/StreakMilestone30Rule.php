<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #9 — streak_milestone_30.
 *
 * Triggers when any tracked streak (meal/fasting/steps/weight log/photo) hits
 * 30 days. Higher-grade celebration than ordinary insights — fires only ONCE
 * per (user, streak_value)，cooldown 365 天 effectively (frontend hides past
 * milestones once read).
 */
class StreakMilestone30Rule extends InsightRule
{
    public const KEY = 'streak_milestone_30';

    public function key(): string
    {
        return self::KEY;
    }

    public function cooldownDays(): int
    {
        return 365;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $maxStreak = $snapshot->maxStreakDays();
        if ($maxStreak < 30) {
            return null;
        }
        // Only fire on the 30/60/100/365 round numbers — avoids monotonic
        // re-fires day after day once over the threshold.
        if (! in_array($maxStreak, [30, 60, 100, 365], true)) {
            return null;
        }

        return new InsightResult(
            headline: $maxStreak.' 天連勝 🌟',
            body: '妳真的做到了。'
                .'不是每個人都能堅持這麼久，要記得對自己說一聲「辛苦了」。',
            detectionPayload: [
                'streak_days' => $maxStreak,
                'streaks' => $snapshot->streaks,
            ],
            actionSuggestions: [
                ['label' => '看看今天的禮物', 'action_key' => 'celebrate_open'],
            ],
        );
    }
}
