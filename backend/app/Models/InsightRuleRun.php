<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SPEC-cross-metric-insight-v1 — debug log of every rule evaluation.
 * Lets us answer "why didn't rule X fire today for user Y?" at the per-day grain.
 *
 * @property int $id
 * @property int $user_id
 * @property string $rule_key
 * @property Carbon $eval_date
 * @property bool $triggered
 * @property ?array<string,mixed> $eval_context
 */
class InsightRuleRun extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'rule_key', 'eval_date', 'triggered', 'eval_context'];

    protected function casts(): array
    {
        return [
            'eval_date' => 'date',
            'triggered' => 'boolean',
            'eval_context' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
