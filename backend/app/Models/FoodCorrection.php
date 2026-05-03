<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SPEC-photo-ai-correction-v2 — audit log for every correction
 * the user makes; feeds user_calibration hints back into ai-service.
 *
 * @property int $user_id
 * @property ?int $meal_dish_id
 * @property string $correction_type
 * @property ?string $original_food_key
 * @property ?string $corrected_food_key
 * @property ?float $original_portion
 * @property ?float $corrected_portion
 * @property ?float $original_confidence
 * @property ?array $context_json
 */
class FoodCorrection extends Model
{
    use HasFactory;

    public const TYPE_FOOD_SWAP = 'food_swap';
    public const TYPE_PORTION_CHANGE = 'portion_change';
    public const TYPE_ADD_MISSING = 'add_missing';
    public const TYPE_REMOVE = 'remove';
    public const TYPE_AI_REFINE = 'ai_refine';

    protected $fillable = [
        'user_id', 'meal_dish_id', 'correction_type',
        'original_food_key', 'corrected_food_key',
        'original_portion', 'corrected_portion', 'original_confidence',
        'context_json',
    ];

    protected function casts(): array
    {
        return [
            'original_portion' => 'float',
            'corrected_portion' => 'float',
            'original_confidence' => 'float',
            'context_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(MealDish::class, 'meal_dish_id');
    }
}
