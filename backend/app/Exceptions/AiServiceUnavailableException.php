<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by AiServiceClient when the Python AI service is not yet wired
 * (current state during Phase A migration). Controllers catch this and
 * return 503 + error_code AI_SERVICE_DOWN.
 */
class AiServiceUnavailableException extends RuntimeException
{
    public function __construct(public readonly string $errorCode = 'AI_SERVICE_DOWN', string $message = 'AI service is not available')
    {
        parent::__construct($message);
    }
}
