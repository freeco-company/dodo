<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SPEC-progress-ritual-v1 — fired ritual event (frontend home banner / chat surface uses this).
 *
 * @property int $id
 * @property int $user_id
 * @property string $ritual_key
 * @property string $idempotency_key
 * @property array<string,mixed> $payload
 * @property Carbon $triggered_at
 * @property ?Carbon $seen_at
 * @property ?Carbon $shared_at
 */
class RitualEvent extends Model
{
    use HasFactory;

    public const KEY_PHOTO_SLIDER = 'progress_photo_slider';
    public const KEY_MONTHLY_COLLAGE = 'monthly_progress_collage';
    public const KEY_OUTFIT_UNLOCK_FULLSCREEN = 'outfit_unlock_fullscreen';
    public const KEY_STREAK_MILESTONE = 'streak_milestone_celebration';
    public const KEY_SEASON_REVEAL = 'season_outfit_reveal';

    protected $fillable = [
        'user_id', 'ritual_key', 'idempotency_key', 'payload',
        'triggered_at', 'seen_at', 'shared_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'triggered_at' => 'datetime',
            'seen_at' => 'datetime',
            'shared_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
