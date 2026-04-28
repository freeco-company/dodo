<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSummary extends Model
{
    use HasPandoraUserUuid;

    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = ['user_id', 'pandora_user_uuid', 'summary_json', 'updated_at'];

    protected function casts(): array
    {
        return [
            'summary_json' => 'array',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
