<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * franchise_leads — 人工聯繫 inbox（**不**自動發訊給客戶）。
 *
 * 重要 UX 邊界（dodo CLAUDE.md / ADR-008）：
 *   這個 table 是**內部分段資料**，不要把它當「行銷對象列表」自動發訊。
 *   業務必須**人工**判斷後才接觸客戶。
 *
 * @property int $id
 * @property string $pandora_user_uuid
 * @property ?int $user_id
 * @property string $source_app
 * @property string $trigger_event
 * @property ?array $trigger_payload
 * @property string $status
 * @property ?string $assigned_to
 * @property ?string $notes
 * @property ?Carbon $contacted_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class FranchiseLead extends Model
{
    use HasFactory;

    protected $table = 'franchise_leads';

    public const STATUS_NEW = 'new';

    public const STATUS_CONTACTING = 'contacting';

    public const STATUS_CONTACTED = 'contacted';

    public const STATUS_CONVERTED = 'converted';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_SILENCED = 'silenced';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_CONTACTING,
        self::STATUS_CONTACTED,
        self::STATUS_CONVERTED,
        self::STATUS_DISMISSED,
        self::STATUS_SILENCED,
    ];

    protected $fillable = [
        'pandora_user_uuid',
        'user_id',
        'source_app',
        'trigger_event',
        'trigger_payload',
        'status',
        'assigned_to',
        'notes',
        'contacted_at',
    ];

    protected $casts = [
        'trigger_payload' => 'array',
        'contacted_at' => 'datetime',
    ];
}
