<?php

namespace App\Services\Ai;

use RuntimeException;

class AiCostCapExceeded extends RuntimeException
{
    public function __construct(
        public readonly float $spentUsd,
        public readonly float $capUsd,
    ) {
        parent::__construct(sprintf(
            'AI monthly cap exceeded: spent=%.4f cap=%.2f USD',
            $spentUsd,
            $capUsd,
        ));
    }

    public function code(): string
    {
        return 'AI_MONTHLY_CAP_EXCEEDED';
    }
}
