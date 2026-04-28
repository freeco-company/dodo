<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property string $event_type
 * @property Carbon $received_at
 */
class GamificationWebhookNonce extends Model
{
    protected $table = 'gamification_webhook_nonces';

    public $timestamps = false;

    protected $fillable = ['event_id', 'event_type', 'received_at'];

    protected $casts = [
        'received_at' => 'datetime',
    ];
}
