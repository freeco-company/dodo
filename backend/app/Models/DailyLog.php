<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_id', 'user_id', 'date',
        'total_score', 'calorie_score', 'protein_score',
        'consistency_score', 'exercise_score', 'hydration_score',
        'total_calories', 'total_protein_g', 'total_carbs_g', 'total_fat_g', 'total_fiber_g',
        'water_ml', 'exercise_minutes', 'meals_logged',
        'weight_kg', 'daily_summary', 'xp_earned',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total_protein_g' => 'float',
            'total_carbs_g' => 'float',
            'total_fat_g' => 'float',
            'total_fiber_g' => 'float',
            'weight_kg' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }
}
