<?php

namespace App\Services\Gamification;

use App\Jobs\PublishAchievementAwardJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 朵朵 → py-service achievement award publisher (ADR-009 catalog §5).
 *
 * Mirrors {@see GamificationPublisher} layout — async via queue, HMAC auth via
 * the same shared secret, env-gated noop, idempotent server-side.
 *
 * The receiving endpoint (POST /api/v1/internal/gamification/achievements/award)
 * is itself idempotent on (pandora_user_uuid, code), so we do not need a local
 * "did we already publish?" check; safest to publish on every detected trigger.
 */
class AchievementPublisher
{
    /**
     * Achievement codes the dodo backend is allowed to award. Must match a row
     * in py-service's gamification_achievements table (seed via the
     * achievements/seed admin endpoint at deploy time). Listed explicitly so
     * a typo at the call-site fails noisily before queueing.
     *
     * @var list<string>
     */
    public const KNOWN_ACHIEVEMENT_CODES = [
        'meal.first_meal',
        'meal.streak_7',
        'meal.streak_30',
        'meal.foodie_10',  // wired in FoodDiscoveryService when user reaches 10 distinct foods
    ];

    public const SOURCE_APP = 'meal';

    public function publish(
        string $pandoraUserUuid,
        string $code,
        string $idempotencyKey,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): void {
        if (! in_array($code, self::KNOWN_ACHIEVEMENT_CODES, true)) {
            Log::warning('[Achievement] unknown achievement code, dropping', [
                'code' => $code,
                'uuid' => $pandoraUserUuid,
            ]);

            return;
        }
        if (! $this->isEnabled()) {
            Log::debug('[Achievement] publisher disabled (no base_url configured); skipping', [
                'code' => $code,
                'uuid' => $pandoraUserUuid,
            ]);

            return;
        }
        if ($pandoraUserUuid === '' || $idempotencyKey === '') {
            Log::warning('[Achievement] empty uuid or idempotency_key, dropping', [
                'code' => $code,
            ]);

            return;
        }

        $body = [
            'pandora_user_uuid' => $pandoraUserUuid,
            'code' => $code,
            'source_app' => self::SOURCE_APP,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => ($occurredAt ?? Carbon::now())->toIso8601String(),
            'metadata' => array_filter($metadata, fn ($v) => $v !== null),
        ];

        PublishAchievementAwardJob::dispatch($body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function send(array $body): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $base = rtrim((string) config('services.pandora_gamification.base_url'), '/');
        $secret = (string) config('services.pandora_gamification.shared_secret');
        $timeout = (int) config('services.pandora_gamification.timeout', 5);

        $response = Http::withHeaders([
            'X-Internal-Secret' => $secret,
            'Accept' => 'application/json',
        ])
            ->timeout($timeout)
            ->retry(2, 200, throw: false)
            ->post($base.'/api/v1/internal/gamification/achievements/award', $body);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'achievement award failed: status=%d body=%s',
                $response->status(),
                substr((string) $response->body(), 0, 200),
            ));
        }
    }

    public function isEnabled(): bool
    {
        $base = (string) config('services.pandora_gamification.base_url');
        $secret = (string) config('services.pandora_gamification.shared_secret');

        return $base !== '' && $secret !== '';
    }
}
