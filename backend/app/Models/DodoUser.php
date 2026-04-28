<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 朵朵端 minimal mirror — 不存任何 PII，只有 display + tier。
 *
 * @property string $pandora_user_uuid
 * @property ?string $display_name
 * @property ?string $avatar_url
 * @property ?string $subscription_tier
 * @property ?Carbon $last_synced_at
 *
 * @see ADR-007 §2.3 — 朵朵作為消費端 App，禁止存 PII
 */
class DodoUser extends Model
{
    protected $table = 'dodo_users';

    protected $primaryKey = 'pandora_user_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'pandora_user_uuid',
        'display_name',
        'avatar_url',
        'subscription_tier',
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];
}
