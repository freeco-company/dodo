<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardEventOffer extends Model
{
    use HasFactory, HasPandoraUserUuid;

    protected $fillable = [
        'legacy_id', 'user_id', 'pandora_user_uuid', 'card_id',
        'offered_at', 'expires_at', 'status', 'play_id', 'event_group',
    ];

    protected function casts(): array
    {
        return [
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function play(): BelongsTo
    {
        return $this->belongsTo(CardPlay::class, 'play_id');
    }
}
