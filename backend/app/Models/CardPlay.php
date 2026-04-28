<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardPlay extends Model
{
    use HasFactory, HasPandoraUserUuid;

    protected $fillable = [
        'legacy_id', 'user_id', 'pandora_user_uuid', 'date', 'card_id', 'card_type',
        'rarity', 'choice_idx', 'correct', 'xp_gained', 'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'answered_at' => 'datetime',
            'correct' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
