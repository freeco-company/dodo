<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Replay-protection nonce store for `/internal/lifecycle/invalidate` (PG-93).
 *
 * @property int $id
 * @property string $nonce
 * @property Carbon $received_at
 */
class LifecycleInvalidateNonce extends Model
{
    protected $table = 'lifecycle_invalidate_nonces';

    public $timestamps = false;

    protected $fillable = ['nonce', 'received_at'];

    protected $casts = [
        'received_at' => 'datetime',
    ];
}
