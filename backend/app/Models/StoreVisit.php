<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreVisit extends Model
{
    use HasFactory, HasPandoraUserUuid;

    public $timestamps = false;

    protected $fillable = [
        'legacy_id', 'user_id', 'pandora_user_uuid', 'store_key',
        'visit_count', 'intent_count', 'first_visit_at', 'last_visit_at',
    ];

    protected function casts(): array
    {
        return [
            'first_visit_at' => 'datetime',
            'last_visit_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
