<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider', 'event_id', 'event_type', 'original_transaction_id',
    'raw_payload', 'processed_at',
])]
class IapWebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
