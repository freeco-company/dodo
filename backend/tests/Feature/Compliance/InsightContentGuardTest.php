<?php

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\Rules\InsightRule;
use App\Services\Insight\RuleRegistry;
use App\Services\Insight\UserDataSnapshot;
use Carbon\CarbonImmutable;
use Pandora\Shared\Compliance\LegalContentSanitizer;

/**
 * SPEC-cross-metric-insight-v1 PR #2 — compliance regression guard.
 *
 * Every rule's free-tier narrative template (headline + body) must pass the
 *集團 compliance sanitizer. New rules added by future PRs auto-enroll via
 * RuleRegistry::all() — no need to update this test.
 *
 * 違規詞清單見 Pandora\Shared\Compliance\LegalContentSanitizer::REPLACEMENTS.
 */
it('every InsightRule narrative template passes the compliance sanitizer', function () {
    $sanitizer = new LegalContentSanitizer;
    $offenders = [];

    foreach (app(RuleRegistry::class)->all() as $rule) {
        // Pull the strings each rule would render. We feed every rule a
        // synthetic snapshot stuffed with values that make all conditional
        // string assembly paths execute (rules guard against null inputs;
        // we want the failure mode here to be 違規詞 only, never null deref).
        $headlines = collectStringsFromRule($rule);
        foreach ($headlines as $label => $text) {
            $hits = $sanitizer->riskReport($text);
            if ($hits) {
                $offenders[$rule->key().':'.$label] = $hits;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Insight free-tier templates contain 違規詞，請改用合規詞。Hits: '
            .json_encode($offenders, JSON_UNESCAPED_UNICODE)
    );
});

/**
 * @return array<string, string>
 */
function collectStringsFromRule(InsightRule $rule): array
{
    $stub = new UserDataSnapshot(
        userId: 0,
        now: CarbonImmutable::now('Asia/Taipei'),
        weight: ['avg_7d' => 56.0, 'avg_prev_7d' => 56.0, 'sd_7d' => 0.1, 'max_delta_4w' => 0.15],
        sleep: ['avg_minutes_7d' => 300, 'avg_minutes_prev_7d' => 360],
        steps: ['total_7d' => 30000, 'total_prev_7d' => 50000, 'days_met_target_7d' => 6],
        meals: [
            'avg_kcal_7d' => 1100, 'sd_kcal_7d' => 50, 'days_logged_7d' => 7,
            'avg_protein_g_7d' => 40, 'late_night_count_7d' => 5,
            'weekend_excess_ratio' => 0.45,
        ],
        fasting: ['streak_days' => 8, 'days_completed_7d' => 7],
        streaks: ['meal_streak' => 30, 'weight_log_streak' => 30, 'recovery_signal' => true],
    );
    $user = new User;
    $user->id = 1;
    $result = $rule->evaluate($user, $stub);

    if ($result instanceof InsightResult) {
        return [
            'headline' => $result->headline,
            'body' => $result->body,
        ];
    }

    return [];
}
