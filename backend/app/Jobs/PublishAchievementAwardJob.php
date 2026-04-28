<?php

namespace App\Jobs;

use App\Services\Gamification\AchievementPublisher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishAchievementAwardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /** @param  array<string, mixed>  $body */
    public function __construct(public array $body) {}

    public function handle(AchievementPublisher $publisher): void
    {
        $publisher->send($this->body);
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('[Achievement] award publish permanently failed', [
            'code' => $this->body['code'] ?? 'unknown',
            'uuid' => $this->body['pandora_user_uuid'] ?? null,
            'idempotency_key' => $this->body['idempotency_key'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
