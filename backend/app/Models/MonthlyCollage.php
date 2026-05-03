<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SPEC-progress-ritual-v1 — auto-generated monthly collage.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $month_start
 * @property array<int,int> $snapshot_ids
 * @property array<string,mixed> $stats_payload
 * @property string $narrative_letter
 * @property ?string $image_path
 * @property int $shared_count
 */
class MonthlyCollage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'month_start', 'snapshot_ids', 'stats_payload',
        'narrative_letter', 'image_path', 'shared_count',
    ];

    protected function casts(): array
    {
        return [
            'month_start' => 'date',
            'snapshot_ids' => 'array',
            'stats_payload' => 'array',
            'shared_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
