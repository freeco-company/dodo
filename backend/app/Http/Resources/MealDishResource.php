<?php

namespace App\Http\Resources;

use App\Models\MealDish;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MealDish
 */
class MealDishResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'meal_id' => $this->meal_id,
            'food_name' => $this->food_name,
            'food_key' => $this->food_key,
            'portion_multiplier' => (float) $this->portion_multiplier,
            'portion_unit' => $this->portion_unit,
            'kcal' => (int) $this->kcal,
            'carb_g' => (float) $this->carb_g,
            'protein_g' => (float) $this->protein_g,
            'fat_g' => (float) $this->fat_g,
            'confidence' => $this->confidence !== null ? (float) $this->confidence : null,
            'confidence_band' => $this->confidenceBand(),
            'source' => $this->source,
            'candidates' => $this->candidates_json ?? [],
            'display_order' => (int) $this->display_order,
        ];
    }

    /**
     * SPEC §3.1 — confidence chip 三色映射，frontend 不重複算邏輯。
     */
    private function confidenceBand(): ?string
    {
        if ($this->confidence === null) {
            return null;
        }
        return match (true) {
            $this->confidence >= 0.85 => 'high',
            $this->confidence >= 0.65 => 'medium',
            default => 'low',
        };
    }
}
