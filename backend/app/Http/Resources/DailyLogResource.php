<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\DailyLog
 */
class DailyLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'scores' => [
                'total' => $this->total_score,
                'calorie' => $this->calorie_score,
                'protein' => $this->protein_score,
                'consistency' => $this->consistency_score,
                'exercise' => $this->exercise_score,
                'hydration' => $this->hydration_score,
            ],
            'macros' => [
                'calories' => $this->total_calories,
                'protein_g' => (float) $this->total_protein_g,
                'carbs_g' => (float) $this->total_carbs_g,
                'fat_g' => (float) $this->total_fat_g,
                'fiber_g' => (float) $this->total_fiber_g,
            ],
            'water_ml' => $this->water_ml,
            'exercise_minutes' => $this->exercise_minutes,
            'meals_logged' => $this->meals_logged,
            'weight_kg' => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            'daily_summary' => $this->daily_summary,
            'xp_earned' => $this->xp_earned,
        ];
    }
}
