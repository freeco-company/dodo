<?php

namespace App\Jobs;

use App\Services\Gamification\GamificationPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background job — POST a gamification event to py-service.
 *
 * Idempotency lives in py-service via UNIQUE(source_app, idempotency_key) so
 * retries are safe. Failures retry up to {@see $tries} times with exponential
 * backoff; final failure is logged for ops.
 */
class PublishGamificationEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  array<string, mixed>  $body  Body to POST to /api/v1/internal/gamification/events
     */
    public function __construct(public array $body) {}

    public function handle(GamificationPublisher $publisher): void
    {
        $publisher->send($this->body);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('[Gamification] event publish permanently failed', [
            'event_kind' => $this->body['event_kind'] ?? 'unknown',
            'uuid' => $this->body['pandora_user_uuid'] ?? null,
            'idempotency_key' => $this->body['idempotency_key'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
