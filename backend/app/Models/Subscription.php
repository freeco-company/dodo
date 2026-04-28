<?php

namespace App\Models;

use App\Models\Concerns\HasPandoraUserUuid;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $pandora_user_uuid
 * @property string $provider
 * @property string|null $provider_subscription_id
 * @property string|null $product_id
 * @property string|null $plan
 * @property string $state
 * @property Carbon|null $started_at
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $grace_until
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $refunded_at
 * @property Carbon|null $last_event_at
 * @property array<string,mixed>|null $raw_payload
 */
#[Fillable([
    'user_id', 'pandora_user_uuid',
    'provider', 'provider_subscription_id', 'product_id', 'plan',
    'state',
    'started_at', 'current_period_start', 'current_period_end',
    'grace_until', 'cancelled_at', 'refunded_at', 'last_event_at',
    'raw_payload',
])]
class Subscription extends Model
{
    use HasPandoraUserUuid;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_until' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'last_event_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
