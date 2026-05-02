<?php

namespace App\Services\Gamification;

use App\Jobs\PublishGamificationEventJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 朵朵 → Pandora Core gamification service 事件發布器（ADR-009 §2.2 / Phase B）。
 *
 * 設計鏡像 {@see \App\Services\Conversion\ConversionEventPublisher}：
 *
 *   1) Auth：HMAC shared secret（X-Internal-Secret header）
 *      對應 py-service `/api/v1/internal/gamification/events`（ADR-009 §2.2 internal endpoint）。
 *
 *   2) Sync vs Async：一律 dispatch 到 queue
 *      User-facing endpoint（記餐、答對卡、開 App）若同步打 py-service，會吃到 conversion module
 *      已知的延遲 + 擴大故障範圍。Phase B 起步同樣走 queue。
 *
 *   3) Idempotency：caller 必須給 idempotency_key
 *      py-service 上 UNIQUE(source_app, idempotency_key) — 重發無害。慣例：
 *      `dodo.{event_kind}.{user_id}.{occurred_unix}` 或 entity-bound
 *      （`dodo.meal_logged.{meal_id}`、`dodo.streak_7.{user_id}.{cycle_seq}`）。
 *
 *   4) Env-gated noop
 *      沒設 PANDORA_GAMIFICATION_BASE_URL 時 publisher 不 dispatch（log debug），
 *      朵朵能在 py-service gamification module 還沒部署的環境下正常跑。
 *
 *   5) source_app 固定 'dodo'
 *      ledger 的 source_app 永久綁本服務識別，B 範圍改名（pandora-meal）後 ledger 仍保留
 *      `dodo` 以維持 idempotency_key 歷史。詳見 docs/migrations/dodo-to-pandora-meal-rename.md。
 *
 * @see /Users/chris/freeco/pandora/docs/adr/ADR-009-cross-app-gamification.md §2-3
 * @see /Users/chris/freeco/pandora/docs/group-gamification-catalog.md §3.1（dodo.* event_kinds）
 */
class GamificationPublisher
{
    public const SOURCE_APP = 'meal';

    /**
     * Whitelist of event_kinds this publisher knows about. Catalog (catalog §3.1)
     * is the source of truth — adding here without py-service catalog update will
     * 422 from py-service. Listed explicitly so a typo at call-site fails noisily
     * before queueing rather than 422-ing in the job retry loop.
     *
     * @var list<string>
     */
    public const KNOWN_EVENT_KINDS = [
        'meal.app_opened',
        'meal.meal_logged',
        'meal.meal_score_80_plus',
        'meal.daily_score_80_plus',
        'meal.streak_3',
        'meal.streak_7',
        'meal.streak_14',
        'meal.streak_30',
        'meal.weekly_review_read',
        'meal.chat_daily',
        'meal.weight_logged',
        'meal.first_meal_of_day',
        'meal.new_food_discovered',
        'meal.card_correct',
        'meal.card_first_solve',
        // SPEC-fasting-timer Phase 2 — publish on completed=true session end.
        'meal.fasting_completed',
        'meal.fasting_streak_7',
    ];

    /**
     * Publish a gamification event for a given pandora_user_uuid.
     *
     * @param  string  $eventKind        e.g. `dodo.meal_logged` (catalog §3.1)
     * @param  string  $idempotencyKey   Stable per logical event; safe to retry
     * @param  array<string, mixed>  $metadata  Optional event-specific extras
     */
    public function publish(
        string $pandoraUserUuid,
        string $eventKind,
        string $idempotencyKey,
        array $metadata = [],
        ?CarbonInterface $occurredAt = null,
    ): void {
        if (! in_array($eventKind, self::KNOWN_EVENT_KINDS, true)) {
            Log::warning('[Gamification] unknown event_kind, dropping', [
                'event_kind' => $eventKind,
                'uuid' => $pandoraUserUuid,
            ]);

            return;
        }

        if (! $this->isEnabled()) {
            Log::debug('[Gamification] publisher disabled (no base_url configured); skipping', [
                'event_kind' => $eventKind,
                'uuid' => $pandoraUserUuid,
            ]);

            return;
        }

        if ($pandoraUserUuid === '' || $idempotencyKey === '') {
            Log::warning('[Gamification] empty uuid or idempotency_key, dropping', [
                'event_kind' => $eventKind,
                'uuid' => $pandoraUserUuid,
                'idempotency_key' => $idempotencyKey,
            ]);

            return;
        }

        $body = [
            'pandora_user_uuid' => $pandoraUserUuid,
            'source_app' => self::SOURCE_APP,
            'event_kind' => $eventKind,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => ($occurredAt ?? Carbon::now())->toIso8601String(),
            'metadata' => array_filter($metadata, fn ($v) => $v !== null),
        ];

        PublishGamificationEventJob::dispatch($body);
    }

    /**
     * Synchronous send used by the queue job. Not called from request path.
     *
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
            ->post($base.'/api/v1/internal/gamification/events', $body);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'gamification publish failed: status=%d body=%s',
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
