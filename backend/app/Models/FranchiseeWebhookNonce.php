<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event_id
 * @property Carbon $received_at
 */
class FranchiseeWebhookNonce extends Model
{
    protected $table = 'franchisee_webhook_nonces';

    public $timestamps = false;

    protected $fillable = ['event_id', 'received_at'];

    protected $casts = [
        'received_at' => 'datetime',
    ];
}
