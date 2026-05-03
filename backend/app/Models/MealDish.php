<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SPEC-photo-ai-correction-v2 — per-dish row of a meal.
 *
 * @property int $meal_id
 * @property string $food_name
 * @property ?string $food_key
 * @property float $portion_multiplier
 * @property ?string $portion_unit
 * @property int $kcal
 * @property float $carb_g
 * @property float $protein_g
 * @property float $fat_g
 * @property ?float $confidence
 * @property string $source
 * @property ?array $candidates_json
 * @property int $display_order
 * @property-read Meal $meal
 */
class MealDish extends Model
{
    use HasFactory;

    public const SOURCE_AI_INITIAL = 'ai_initial';
    public const SOURCE_AI_REFINED = 'ai_refined';
    public const SOURCE_USER_SWAPPED = 'user_swapped';
    public const SOURCE_USER_MANUAL = 'user_manual';

    protected $fillable = [
        'meal_id', 'food_name', 'food_key', 'portion_multiplier', 'portion_unit',
        'kcal', 'carb_g', 'protein_g', 'fat_g', 'confidence', 'source',
        'candidates_json', 'display_order',
    ];

    protected function casts(): array
    {
        return [
            'portion_multiplier' => 'float',
            'kcal' => 'integer',
            'carb_g' => 'float',
            'protein_g' => 'float',
            'fat_g' => 'float',
            'confidence' => 'float',
            'candidates_json' => 'array',
            'display_order' => 'integer',
        ];
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(FoodCorrection::class);
    }
}
