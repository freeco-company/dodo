<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FoodDiscovery extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'legacy_id', 'user_id', 'food_id',
        'first_seen_at', 'times_eaten', 'best_score', 'is_shiny',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'is_shiny' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class, 'food_id');
    }
}
