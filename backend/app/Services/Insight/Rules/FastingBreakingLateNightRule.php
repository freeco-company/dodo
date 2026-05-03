<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #6 — fasting_breaking_late_night.
 * ≥3 fasting sessions ended after 22:00 (or before 04:00) in past 7 days.
 *
 * Pure-snapshot read: aggregator pre-computes late_breaks_7d so this rule
 * stays free of DB access (keeps unit tests + ContentGuard hermetic).
 */
class FastingBreakingLateNightRule extends InsightRule
{
    public const KEY = 'fasting_breaking_late_night';

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $lateBreaks = $snapshot->fastingLateBreaks7d();
        if ($lateBreaks < 3) {
            return null;
        }

        return new InsightResult(
            headline: '妳常在晚上 10 點後吃 🌙',
            body: '這週有 '.$lateBreaks.' 次斷食結束在深夜，可能是壓力或睡前餓。'
                .'要不要試試提早晚餐、或睡前一杯溫水？',
            detectionPayload: ['late_break_count' => $lateBreaks],
            actionSuggestions: [['label' => '看高蛋白消夜清單', 'action_key' => 'snack_late_protein']],
        );
    }
}
