<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SPEC-cross-metric-insight-v1 — fired insight (read by frontend chat / push / weekly report).
 *
 * @property int $id
 * @property int $user_id
 * @property string $insight_key
 * @property string $idempotency_key
 * @property array<string,mixed> $detection_payload
 * @property string $narrative_headline
 * @property ?string $narrative_body
 * @property array<int,array<string,mixed>> $action_suggestion
 * @property string $source
 * @property Carbon $fired_at
 * @property ?Carbon $read_at
 * @property ?Carbon $pushed_at
 * @property ?Carbon $dismissed_at
 */
class Insight extends Model
{
    use HasFactory;

    public const SOURCE_RULE_ENGINE = 'rule_engine';
    public const SOURCE_AI_NARRATIVE_PAID = 'ai_narrative_paid';

    protected $fillable = [
        'user_id', 'insight_key', 'idempotency_key',
        'detection_payload', 'narrative_headline', 'narrative_body',
        'action_suggestion', 'source',
        'fired_at', 'read_at', 'pushed_at', 'dismissed_at',
    ];

    protected function casts(): array
    {
        return [
            'detection_payload' => 'array',
            'action_suggestion' => 'array',
            'fired_at' => 'datetime',
            'read_at' => 'datetime',
            'pushed_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
