<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ?array $correction_data
 * @property ?array $food_components
 * @property ?array $matched_food_ids
 * @property ?string $photo_url
 * @property int $id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MealDish> $dishes
 */
class Meal extends Model
{
    use HasFactory, HasPandoraUserUuid;

    protected $fillable = [
        'legacy_id', 'user_id', 'pandora_user_uuid', 'daily_log_id', 'date', 'meal_type',
        'photo_url', 'food_name', 'recognized_via', 'food_components', 'matched_food_ids',
        'serving_weight_g',
        'calories', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g', 'sodium_mg', 'sugar_g',
        'meal_score', 'coach_response', 'dodo_comment',
        'user_corrected', 'correction_data', 'ai_confidence', 'ai_raw_response',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'food_components' => 'array',
            'matched_food_ids' => 'array',
            'correction_data' => 'array',
            'user_corrected' => 'boolean',
            'serving_weight_g' => 'float',
            'protein_g' => 'float',
            'carbs_g' => 'float',
            'fat_g' => 'float',
            'fiber_g' => 'float',
            'sodium_mg' => 'float',
            'sugar_g' => 'float',
            'ai_confidence' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(DailyLog::class);
    }

    /** SPEC-photo-ai-correction-v2 — per-dish breakdown. */
    public function dishes(): HasMany
    {
        return $this->hasMany(MealDish::class)->orderBy('display_order');
    }
}
