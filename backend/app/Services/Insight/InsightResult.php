<?php

namespace App\Services\Insight;

/**
 * SPEC-cross-metric-insight-v1 PR #1 — return value of InsightRule::evaluate().
 *
 * Detection payload is the raw datapoints that triggered the rule (saved on
 * the Insight row for debug + AI narrative context). Headline/body are
 * deterministic templates; ai-service narrative pass overrides body for paid
 * users in PR #3.
 */
final class InsightResult
{
    /**
     * @param  array<string,mixed>  $detectionPayload
     * @param  array<int,array{label: string, action_key: string, deeplink?: string}>  $actionSuggestions
     */
    public function __construct(
        public readonly string $headline,
        public readonly string $body,
        public readonly array $detectionPayload,
        public readonly array $actionSuggestions = [],
    ) {}
}
