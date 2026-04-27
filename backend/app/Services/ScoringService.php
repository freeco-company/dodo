<?php

namespace App\Services;

/**
 * Translated from ai-game/src/services/scoring.ts.
 * Rule-based 0-100 daily score = calorie(30)+protein(20)+consistency(20)+exercise(15)+hydration(15).
 */
class ScoringService
{
    public static function calorie(float $intake, float $target): int
    {
        if ($target <= 0) return 0;
        $r = $intake / $target;
        if ($r >= 0.85 && $r <= 1.05) return 30;
        if ($r >= 0.75 && $r <= 1.15) return 22;
        if ($r >= 0.65 && $r <= 1.25) return 15;
        if ($r < 0.5) return 5;
        return 8;
    }

    public static function protein(float $intake, float $target): int
    {
        if ($target <= 0) return 0;
        $r = $intake / $target;
        if ($r >= 0.9) return 20;
        if ($r >= 0.7) return 14;
        if ($r >= 0.5) return 8;
        return 3;
    }

    public static function consistency(int $meals): int
    {
        if ($meals >= 3) return 20;
        if ($meals >= 2) return 14;
        if ($meals >= 1) return 8;
        return 0;
    }

    public static function exercise(int $min): int
    {
        if ($min >= 30) return 15;
        if ($min >= 15) return 10;
        if ($min >= 5) return 5;
        return 0;
    }

    public static function hydration(int $ml): int
    {
        if ($ml >= 2000) return 15;
        if ($ml >= 1500) return 11;
        if ($ml >= 1000) return 7;
        if ($ml >= 500) return 3;
        return 0;
    }

    /**
     * @return array{calorie:int, protein:int, consistency:int, exercise:int, hydration:int, total:int}
     */
    public static function daily(array $i): array
    {
        $c = self::calorie((float)($i['total_calories'] ?? 0), (float)($i['daily_calorie_target'] ?? 1800));
        $p = self::protein((float)($i['total_protein_g'] ?? 0), (float)($i['daily_protein_target_g'] ?? 80));
        $cn = self::consistency((int)($i['meals_logged'] ?? 0));
        $e = self::exercise((int)($i['exercise_minutes'] ?? 0));
        $h = self::hydration((int)($i['water_ml'] ?? 0));
        return [
            'calorie' => $c, 'protein' => $p, 'consistency' => $cn,
            'exercise' => $e, 'hydration' => $h,
            'total' => $c + $p + $cn + $e + $h,
        ];
    }
}
