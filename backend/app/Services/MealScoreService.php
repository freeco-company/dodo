<?php

namespace App\Services;

use App\Models\Meal;
use App\Models\User;

/**
 * Per-meal nutrition score (0-100). Ported from
 * `ai-game/src/services/scoring.ts::calculateMealScore` with the four
 * reasonableness fixes called out at PR review time:
 *
 *   1) Use **meal_type-specific calorie target** (breakfast 27% / lunch 33% /
 *      dinner 30% / snack 10% of daily target) rather than the original
 *      `remaining_calorie_budget`. The Node version's "first meal of the day
 *      always gets max points because budget is 100%" bug is gone.
 *   2) Return `null` (no score, don't write meal_score) when calories are
 *      missing or 0 — punishing unscored meals masquerades as bad nutrition.
 *   3) Symmetric deviation curve around the meal-type ideal — both "way over"
 *      and "way under" lose points; "near ideal" wins.
 *   4) Reduce magic numbers; bands are documented inline.
 *
 * Out of scope (technical debt logged for v2 review):
 *   - Time-of-day plausibility check (3am dinner shouldn't score high)
 *   - Diversity bonus from matched_food_ids count (depends on AI matching
 *     write path; today most meals have empty matched_food_ids)
 *
 * @see ADR-009 §3.1 catalog `dodo.meal_score_80_plus`
 */
class MealScoreService
{
    /** Calorie share of daily target by meal type. Sum is 1.0. */
    public const MEAL_TYPE_SHARE = [
        'breakfast' => 0.27,
        'lunch' => 0.33,
        'dinner' => 0.30,
        'snack' => 0.10,
    ];

    public const DEFAULT_DAILY_CALORIE_TARGET = 1800;

    /**
     * Compute a 0-100 score for this meal, or null when there isn't enough
     * data to score reliably (e.g. user logged a meal name but no nutrition
     * numbers — common for manual freeform entries).
     */
    public function compute(Meal $meal, User $user): ?int
    {
        $calories = (int) ($meal->calories ?? 0);
        if ($calories <= 0) {
            return null;
        }

        $score = 50;

        // 1) Calorie fit vs meal-type-specific target
        $share = self::MEAL_TYPE_SHARE[$meal->meal_type] ?? null;
        if ($share !== null) {
            $dailyTarget = (int) ($user->daily_calorie_target ?? self::DEFAULT_DAILY_CALORIE_TARGET);
            if ($dailyTarget > 0) {
                $idealCal = $dailyTarget * $share;
                // Symmetric deviation from ideal calorie share.
                // |meal/ideal - 1|: 0 = perfect, >0.5 = severely off
                $deviation = abs($calories / $idealCal - 1.0);
                if ($deviation <= 0.15) {
                    $score += 20;          // within 15% of ideal
                } elseif ($deviation <= 0.30) {
                    $score += 12;          // within 30%
                } elseif ($deviation <= 0.50) {
                    $score += 5;           // within 50% — acceptable
                } else {
                    // Heavily over OR heavily under both penalised, but
                    // "way over" is worse health-wise than "way under".
                    $score += $calories > $idealCal ? -15 : -5;
                }
            }
        }

        // 2) Protein density: g per 100 kcal (≥6 = "protein-forward")
        $proteinG = (float) ($meal->protein_g ?? 0);
        $density = ($proteinG / $calories) * 100;
        if ($density >= 8) {
            $score += 15;
        } elseif ($density >= 5) {
            $score += 10;
        } elseif ($density >= 3) {
            $score += 4;
        }

        // 3) Fibre bonus
        $fibreG = (float) ($meal->fiber_g ?? 0);
        if ($fibreG >= 8) {
            $score += 8;
        } elseif ($fibreG >= 5) {
            $score += 5;
        } elseif ($fibreG >= 3) {
            $score += 2;
        }

        // 4) Sodium penalty (single-meal upper bound)
        $sodiumMg = (float) ($meal->sodium_mg ?? 0);
        if ($sodiumMg >= 1800) {
            $score -= 10;
        } elseif ($sodiumMg >= 1200) {
            $score -= 5;
        }

        // 5) Sugar penalty — snacks held to a stricter threshold
        $sugarG = (float) ($meal->sugar_g ?? 0);
        $sugarThreshold = $meal->meal_type === 'snack' ? 15 : 25;
        if ($sugarG >= $sugarThreshold * 2) {
            $score -= 12;
        } elseif ($sugarG >= $sugarThreshold) {
            $score -= 6;
        }

        return max(0, min(100, $score));
    }
}
