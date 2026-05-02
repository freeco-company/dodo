<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property float $value
 * @property string $unit
 * @property Carbon $recorded_at
 * @property string $source
 * @property ?array<string,mixed> $raw_payload
 */
class HealthMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'type', 'value', 'unit', 'recorded_at', 'source', 'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'value' => 'float',
            'raw_payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
