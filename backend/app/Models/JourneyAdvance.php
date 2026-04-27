<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JourneyAdvance extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_id', 'user_id', 'cycle', 'day', 'reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
