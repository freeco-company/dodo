<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $mode
 * @property int $target_duration_minutes
 * @property Carbon $started_at
 * @property ?Carbon $ended_at
 * @property bool $completed
 * @property ?string $last_pushed_phase
 * @property ?Carbon $eating_window_started_at
 * @property string $source_app
 */
class FastingSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'mode', 'target_duration_minutes',
        'started_at', 'ended_at', 'completed',
        'last_pushed_phase', 'eating_window_started_at', 'source_app',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'eating_window_started_at' => 'datetime',
            'target_duration_minutes' => 'integer',
            'completed' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}
