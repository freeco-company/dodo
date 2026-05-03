<?php

namespace App\Http\Resources;

use App\Models\MealDish;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Meal
 */
class MealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date?->toDateString(),
            'meal_type' => $this->meal_type,
            'photo_url' => $this->photo_url,
            'food_name' => $this->food_name,
            'food_components' => $this->food_components ?? [],
            'serving_weight_g' => $this->serving_weight_g !== null ? (float) $this->serving_weight_g : null,
            'macros' => [
                'calories' => $this->calories,
                'protein_g' => (float) $this->protein_g,
                'carbs_g' => (float) $this->carbs_g,
                'fat_g' => (float) $this->fat_g,
                'fiber_g' => (float) $this->fiber_g,
                'sodium_mg' => (float) $this->sodium_mg,
                'sugar_g' => (float) $this->sugar_g,
            ],
            'meal_score' => $this->meal_score,
            'coach_response' => $this->coach_response,
            'ai_confidence' => $this->ai_confidence !== null ? (float) $this->ai_confidence : null,
            // SPEC-photo-ai-correction-v2 PR #4 — per-dish breakdown for correction UI.
            // Empty array when meal pre-dates v2 (legacy meals show single-blob UI).
            'dishes' => MealDishResource::collection($this->dishes),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
