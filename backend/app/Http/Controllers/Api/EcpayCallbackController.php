<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ecpay\EcpayClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Phase E — ECPay server-to-server callbacks.
 *
 *   POST /api/ecpay/notify   — PeriodReturnURL / first auth
 *   POST /api/ecpay/return   — alternate alias for ReturnURL (server)
 *   GET  /api/ecpay/client-back — user-facing redirect target
 *
 * ECPay expects the body "1|OK" (text/plain) on success. Anything else
 * (including non-200 status) makes them retry up to 5 times. We always
 * return "1|OK" on signature-valid payloads (even if the subscription
 * lookup misses) — the retry only helps if the issue is transient on
 * our side. Signature failures get "0|invalid_signature" so the operator
 * sees them in the ECPay merchant console.
 */
class EcpayCallbackController extends Controller
{
    public function __construct(private readonly EcpayClient $client) {}

    public function notify(Request $request): Response
    {
        $params = $request->all();
        $callback = $this->client->applyNotification($params, 'period');

        if (! $callback->signature_valid) {
            return response('0|invalid_signature', 400, ['Content-Type' => 'text/plain']);
        }

        return response('1|OK', 200, ['Content-Type' => 'text/plain']);
    }

    /** ReturnURL alias — same handler but tagged 'auth' for the first authorisation. */
    public function returnUrl(Request $request): Response
    {
        $params = $request->all();
        $callback = $this->client->applyNotification($params, 'auth');

        if (! $callback->signature_valid) {
            return response('0|invalid_signature', 400, ['Content-Type' => 'text/plain']);
        }

        return response('1|OK', 200, ['Content-Type' => 'text/plain']);
    }
}
