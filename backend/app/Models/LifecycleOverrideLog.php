<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Admin-driven lifecycle override audit row.
 *
 * @property int $id
 * @property string $pandora_user_uuid
 * @property ?string $from_status
 * @property string $to_status
 * @property string $reason
 * @property string $actor_email
 * @property bool $succeeded
 * @property ?string $error
 * @property Carbon $created_at
 */
class LifecycleOverrideLog extends Model
{
    protected $table = 'lifecycle_override_logs';

    public $timestamps = false;

    protected $fillable = [
        'pandora_user_uuid',
        'from_status',
        'to_status',
        'reason',
        'actor_email',
        'succeeded',
        'error',
        'created_at',
    ];

    protected $casts = [
        'succeeded' => 'bool',
        'created_at' => 'datetime',
    ];
}
