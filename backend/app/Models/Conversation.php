<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'legacy_id', 'user_id', 'role', 'content',
        'scenario', 'model_used', 'tokens_used',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
