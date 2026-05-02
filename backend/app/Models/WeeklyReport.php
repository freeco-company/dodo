<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property ?string $pandora_user_uuid
 * @property Carbon $week_start
 * @property Carbon $week_end
 * @property ?float $avg_score
 * @property ?float $weight_change
 * @property int $shared_count
 * @property ?string $letter_content
 */
class WeeklyReport extends Model
{
    use HasFactory, HasPandoraUserUuid;

    protected $fillable = [
        'legacy_id', 'user_id', 'pandora_user_uuid', 'week_start', 'week_end',
        'avg_score', 'daily_scores', 'weight_change',
        'level_before', 'level_after', 'top_foods',
        'letter_content', 'read_at', 'shared_count',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'week_end' => 'date',
            'avg_score' => 'float',
            'weight_change' => 'float',
            'daily_scores' => 'array',
            'top_foods' => 'array',
            'read_at' => 'datetime',
            'shared_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
