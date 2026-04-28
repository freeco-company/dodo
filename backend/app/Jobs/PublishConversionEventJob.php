<?php

namespace App\Jobs;

use App\Services\Conversion\ConversionEventPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background job — POST a conversion event to py-service.
 *
 * Failures retry up to {@see $tries} times with exponential backoff. After
 * the final attempt, `failed()` logs the dropped event so ops can investigate
 * (no full DLQ in v1 — Laravel's `failed_jobs` table is the safety net).
 */
class PublishConversionEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int Job retry count */
    public int $tries = 3;

    /** @var int Backoff in seconds between retries */
    public int $backoff = 30;

    /**
     * @param array<string, mixed> $body  Body to POST to /api/v1/internal/events
     */
    public function __construct(public array $body) {}

    public function handle(ConversionEventPublisher $publisher): void
    {
        $publisher->send($this->body);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('[Conversion] event publish permanently failed', [
            'event_type' => $this->body['event_type'] ?? 'unknown',
            'uuid' => $this->body['pandora_user_uuid'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
