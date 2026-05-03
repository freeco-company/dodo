<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SPEC-pikmin-walk-v1 — 每日步數 session。
 *
 * Phase ladder（Pikmin Bloom 風）：
 *   seed   0–1,999
 *   sprout 2,000–4,999
 *   bloom  5,000–7,999    ← 朵朵 partial celebration
 *   fruit  8,000+         ← steps_goal_achieved publish + outfit/cards/island unlock
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $walk_date
 * @property int $total_steps
 * @property string $peak_phase
 * @property ?array $mini_dodos_summoned_json
 * @property bool $goal_published
 * @property ?Carbon $last_synced_at
 */
class DailyWalkSession extends Model
{
    use HasFactory;

    public const PHASE_SEED = 'seed';
    public const PHASE_SPROUT = 'sprout';
    public const PHASE_BLOOM = 'bloom';
    public const PHASE_FRUIT = 'fruit';

    public const PHASES_ORDER = [self::PHASE_SEED, self::PHASE_SPROUT, self::PHASE_BLOOM, self::PHASE_FRUIT];

    public const PHASE_THRESHOLDS = [
        self::PHASE_SEED => 0,
        self::PHASE_SPROUT => 2000,
        self::PHASE_BLOOM => 5000,
        self::PHASE_FRUIT => 8000,
    ];

    protected $fillable = [
        'user_id', 'walk_date', 'total_steps', 'peak_phase',
        'mini_dodos_summoned_json', 'goal_published', 'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'walk_date' => 'date',
            'mini_dodos_summoned_json' => 'array',
            'goal_published' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function phaseFromSteps(int $steps): string
    {
        if ($steps >= self::PHASE_THRESHOLDS[self::PHASE_FRUIT]) {
            return self::PHASE_FRUIT;
        }
        if ($steps >= self::PHASE_THRESHOLDS[self::PHASE_BLOOM]) {
            return self::PHASE_BLOOM;
        }
        if ($steps >= self::PHASE_THRESHOLDS[self::PHASE_SPROUT]) {
            return self::PHASE_SPROUT;
        }

        return self::PHASE_SEED;
    }

    /**
     * Returns true iff `next` phase is strictly later than `prev` in the ladder.
     */
    public static function phaseIsHigher(string $next, string $prev): bool
    {
        return array_search($next, self::PHASES_ORDER, true) > array_search($prev, self::PHASES_ORDER, true);
    }
}
