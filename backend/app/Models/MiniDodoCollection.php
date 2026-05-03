<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SPEC-pikmin-walk-v1 — 一隻 mini-dodo 召喚紀錄。
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $collected_on
 * @property string $color
 * @property string $source_kind
 * @property ?int $source_ref_id
 * @property ?string $source_detail
 * @property Carbon $collected_at
 */
class MiniDodoCollection extends Model
{
    use HasFactory;

    public const COLORS = ['red', 'green', 'blue', 'yellow', 'purple'];
    public const SOURCE_MEAL = 'meal';
    public const SOURCE_STEPS = 'steps';
    public const SOURCE_FASTING = 'fasting';
    public const SOURCE_PHOTO = 'photo';

    /**
     * 5 色 mini-dodo 對應 5 大營養素 / 行為類別。
     * 合規：用「均衡 / 活力 / 日常」中性詞，不暗示療效。
     */
    public const COLOR_MEANING = [
        'red' => '蛋白質均衡',
        'green' => '蔬菜纖維',
        'blue' => '水分日常',
        'yellow' => '好油適量',
        'purple' => '全穀類選擇',
    ];

    protected $fillable = [
        'user_id', 'collected_on', 'color', 'source_kind',
        'source_ref_id', 'source_detail', 'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'collected_on' => 'date',
            'collected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
