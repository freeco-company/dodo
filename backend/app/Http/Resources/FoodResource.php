<?php

namespace App\Http\Resources;

use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Food
 */
class FoodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_zh' => $this->name_zh,
            'name_en' => $this->name_en,
            'category' => $this->category,
            'brand' => $this->brand,
            'element' => $this->element,
            'serving_description' => $this->serving_description,
            'serving_weight_g' => $this->serving_weight_g !== null ? (float) $this->serving_weight_g : null,
            'calories' => $this->calories !== null ? (int) $this->calories : null,
            'protein_g' => $this->protein_g !== null ? (float) $this->protein_g : null,
            'carbs_g' => $this->carbs_g !== null ? (float) $this->carbs_g : null,
            'fat_g' => $this->fat_g !== null ? (float) $this->fat_g : null,
            'fiber_g' => (float) $this->fiber_g,
            'sodium_mg' => (float) $this->sodium_mg,
            'sugar_g' => (float) $this->sugar_g,
            'aliases' => $this->aliases ?? [],
            'verified' => (bool) $this->verified,
        ];
    }
}
