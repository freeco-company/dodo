<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Food extends Model
{
    use HasFactory;

    protected $table = 'food_database';

    protected $fillable = [
        'legacy_id', 'name_zh', 'name_en', 'category', 'brand', 'element',
        'serving_description', 'serving_weight_g',
        'calories', 'protein_g', 'carbs_g', 'fat_g', 'fiber_g', 'sodium_mg', 'sugar_g',
        'variants', 'aliases', 'source', 'verified',
    ];

    protected function casts(): array
    {
        return [
            'serving_weight_g' => 'float',
            'protein_g' => 'float',
            'carbs_g' => 'float',
            'fat_g' => 'float',
            'fiber_g' => 'float',
            'sodium_mg' => 'float',
            'sugar_g' => 'float',
            'variants' => 'array',
            'aliases' => 'array',
            'verified' => 'boolean',
        ];
    }

    public function discoveries(): HasMany
    {
        return $this->hasMany(FoodDiscovery::class, 'food_id');
    }
}
