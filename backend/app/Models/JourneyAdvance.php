<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JourneyAdvance extends Model
{
    use HasFactory, HasPandoraUserUuid;

    protected $fillable = [
        'legacy_id', 'user_id', 'pandora_user_uuid', 'cycle', 'day', 'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
