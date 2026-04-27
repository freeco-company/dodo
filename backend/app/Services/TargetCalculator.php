<?php

namespace App\Services;

/**
 * 將 ai-game/src/services/targets.ts 翻譯成 Laravel。
 * 計算每日卡路里 / 蛋白質 / 水量目標。
 *
 * 公式：Mifflin-St Jeor BMR × activity factor × (1 - 減重赤字 0.15)
 */
class TargetCalculator
{
    private const ACTIVITY_FACTORS = [
        'sedentary' => 1.2,
        'light' => 1.375,
        'moderate' => 1.55,
        'active' => 1.725,
    ];

    /**
     * @param  array{weight_kg: float, height_cm: float, age: int, gender: string, activity_level: string, goal_weight_kg?: float|null}  $input
     * @return array{daily_calorie_target: int, daily_protein_target_g: int, daily_water_goal_ml: int}
     */
    public static function compute(array $input): array
    {
        $w = $input['weight_kg'];
        $h = $input['height_cm'];
        $age = $input['age'];
        $gender = $input['gender'] ?? 'female';

        $bmr = $gender === 'male'
            ? 10 * $w + 6.25 * $h - 5 * $age + 5
            : 10 * $w + 6.25 * $h - 5 * $age - 161;

        $factor = self::ACTIVITY_FACTORS[$input['activity_level'] ?? 'light'] ?? 1.375;
        $tdee = $bmr * $factor;

        $deficit = isset($input['goal_weight_kg']) && $input['goal_weight_kg'] < $w ? 0.15 : 0;
        $target = max(1200, (int) round($tdee * (1 - $deficit)));

        // 蛋白質：1.6 g/kg 體重（中等活動量）
        $protein = (int) round($w * 1.6);

        // 水量：35 ml/kg
        $water = max(2000, (int) round($w * 35));

        return [
            'daily_calorie_target' => $target,
            'daily_protein_target_g' => $protein,
            'daily_water_goal_ml' => $water,
        ];
    }
}
