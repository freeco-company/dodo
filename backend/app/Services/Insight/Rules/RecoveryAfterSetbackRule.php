<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #12 — recovery_after_setback.
 * Aggregator sets streak_recovery_signal when prior 7-day streak broke + this
 * week ≥3 days back. Celebrates the comeback (often higher meaning than streak itself).
 */
class RecoveryAfterSetbackRule extends InsightRule
{
    public const KEY = 'recovery_after_setback';

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        if (! $snapshot->streakRecoverySignal()) {
            return null;
        }

        return new InsightResult(
            headline: '妳又回來了 ✨',
            body: '這比連勝更難。'
                .'掉了再撿起來，是真實的習慣養成的樣子。',
            detectionPayload: ['signal' => 'recovery'],
            actionSuggestions: [['label' => '看朵朵手寫信', 'action_key' => 'celebrate_letter']],
        );
    }
}
