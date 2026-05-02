<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $taken_at
 * @property ?int $weight_g
 * @property ?string $mood
 * @property ?string $notes
 * @property ?string $photo_ref
 */
class ProgressSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'taken_at', 'weight_g', 'mood', 'notes', 'photo_ref',
    ];

    protected function casts(): array
    {
        return [
            'taken_at' => 'datetime',
            'weight_g' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
