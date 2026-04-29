<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends Model
{
    use HasFactory, HasPandoraUserUuid;

    protected $table = 'usage_logs';

    protected $fillable = [
        'legacy_id', 'user_id', 'pandora_user_uuid', 'date', 'kind', 'model',
        'tokens', 'input_tokens', 'output_tokens', 'latency_ms',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
