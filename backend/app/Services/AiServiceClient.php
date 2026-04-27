<?php

namespace App\Services;

use App\Exceptions\AiServiceUnavailableException;
use App\Models\User;

/**
 * Abstract client for the Python FastAPI AI microservice (per ADR-002).
 *
 * Currently always throws AiServiceUnavailableException — the service is not
 * wired yet. Once the Python service is up, swap the stubs to real HTTP calls
 * (e.g. via Http::baseUrl(env('AI_SERVICE_URL'))->...) without changing any
 * controller code.
 */
class AiServiceClient
{
    /**
     * Scan a meal photo. Will return identified food + nutrition.
     * @param array<string,mixed> $context
     */
    public function scanMeal(User $user, string $imageUrl, array $context = []): array
    {
        throw new AiServiceUnavailableException();
    }

    /**
     * Estimate nutrition from text description.
     * @param array<string,mixed> $context
     */
    public function describeMeal(User $user, string $description, array $context = []): array
    {
        throw new AiServiceUnavailableException();
    }

    /**
     * Send a chat message + history, get assistant reply.
     * @param array<int, array{role:string, content:string}> $history
     */
    public function chat(User $user, string $message, array $history = [], ?string $scenario = null): array
    {
        throw new AiServiceUnavailableException();
    }
}
