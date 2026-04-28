<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Phase E — thrown when an IAP code path is reached but the corresponding
 * provider credentials are not configured (and stub mode is off). Renders as
 * a 503 to the client; ops should treat as "monetisation route mounted before
 * keys provisioned".
 */
class IapNotConfiguredException extends RuntimeException
{
    public function __construct(string $provider, ?string $hint = null)
    {
        $msg = "IAP provider '{$provider}' is not configured.";
        if ($hint) {
            $msg .= " {$hint}";
        }
        parent::__construct($msg);
    }

    /** Return a 503 with a stable error code so the App can branch on it. */
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'IAP_NOT_CONFIGURED',
            'message' => $this->getMessage(),
        ], 503);
    }
}
