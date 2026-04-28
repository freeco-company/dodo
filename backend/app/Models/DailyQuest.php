<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyQuest extends Model
{
    use HasFactory, HasPandoraUserUuid;

    public $timestamps = false;

    protected $fillable = [
        'legacy_id', 'user_id', 'pandora_user_uuid', 'date', 'quest_key',
        'target', 'progress', 'completed_at', 'reward_xp',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
