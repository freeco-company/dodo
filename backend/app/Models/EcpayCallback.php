<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'merchant_trade_no', 'trade_no', 'rtn_code', 'rtn_msg',
    'callback_kind', 'raw_payload', 'signature_valid', 'processed_at',
])]
class EcpayCallback extends Model
{
    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}
