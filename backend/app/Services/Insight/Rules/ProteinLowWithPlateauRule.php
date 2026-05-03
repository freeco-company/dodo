<?php

namespace App\Services\Insight\Rules;

use App\Models\User;
use App\Services\Insight\InsightResult;
use App\Services\Insight\UserDataSnapshot;

/**
 * SPEC §2 rule #8 — protein_low_with_strength_decline (simplified to plateau).
 * avg_protein < 1g/kg body weight + weight plateau.
 */
class ProteinLowWithPlateauRule extends InsightRule
{
    public const KEY = 'protein_low_with_plateau';

    public function key(): string
    {
        return self::KEY;
    }

    public function evaluate(User $user, UserDataSnapshot $snapshot): ?InsightResult
    {
        $protein = $snapshot->avgProteinG7d();
        $weight = $snapshot->weightAvg7d();
        $weightPrev = $snapshot->weightAvgPrev7d();
        if ($protein === null || $weight === null || $weightPrev === null) {
            return null;
        }
        $proteinPerKg = $protein / max($weight, 0.01);
        if ($proteinPerKg >= 1.0) {
            return null;
        }
        // plateau condition (similar to WeightPlateau but looser)
        if (abs($weight - $weightPrev) > 0.3) {
            return null;
        }

        return new InsightResult(
            headline: '蛋白質有點少 🥩',
            body: '平均每天 '.round($protein).'g（約 '.round($proteinPerKg, 2).'g/kg），'
                .'平台期更需要補。要不要把蛋白目標往上加 20g？',
            detectionPayload: [
                'avg_protein_g' => round($protein, 1),
                'protein_per_kg' => round($proteinPerKg, 2),
                'weight_kg' => round($weight, 1),
            ],
            actionSuggestions: [['label' => '提高蛋白目標', 'action_key' => 'goal_protein_up']],
        );
    }
}
