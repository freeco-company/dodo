<?php

namespace App\Services\Conversion;

use App\Jobs\PublishConversionEventJob;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 朵朵 → Pandora Core conversion service 事件發布器（ADR-003 §2.3 + ADR-008 §2.3）。
 *
 * 設計決策：
 *
 *   1) Auth：HMAC shared secret（X-Internal-Secret header）
 *      - 對比兩個替代：(a) per-user platform JWT pass-through、
 *        (b) per-product service-to-service JWT。
 *      - 採 (c) HMAC 因為：朵朵目前不持有 user platform JWT（ADR-007 Phase 5
 *        才接 token exchange），用 (a) 等於要先做完 Phase 5；用 (b) 又要
 *        platform 多發一套 service token + key rotation。HMAC 是 internal
 *        network 的最小可行 auth，py-service /api/v1/internal/events 端點
 *        對應就是這條 path。
 *      - 安全邊界：secret 透過 env (PANDORA_CONVERSION_SHARED_SECRET) 注入；
 *        production 應走 internal VPC，不暴露在 public internet。
 *
 *   2) Sync vs Async：一律 dispatch 到 queue（after_commit）
 *      - User-facing endpoint 不能因為 conversion service 慢 / 掛而 block。
 *      - Failure 不影響核心 user request；retry / dead letter 由 queue handle。
 *
 *   3) Env-gated noop
 *      - 沒設 PANDORA_CONVERSION_BASE_URL 時 publisher 不 dispatch（log debug）。
 *      - 朵朵能在 py-service 還沒部署的環境下正常跑（dev / Phase A 期間 fallback）。
 *
 * @see /Users/chris/freeco/pandora/docs/adr/ADR-003-loyalist-to-franchisee-conversion.md §2.3
 */
class ConversionEventPublisher
{
    /**
     * Events whose firing may cause py-service to evaluate a lifecycle stage transition.
     * After dispatching such an event we forget the per-user lifecycle cache so the next
     * `LifecycleClient::getStatus()` re-fetches the (potentially upgraded) stage.
     *
     * Why this set (ADR-008 §2.2 / §2.3):
     *   - `engagement.deep` (14 days, ADR-008 §2.1 §2.2) — on-ramp from visitor → loyalist
     *   - `franchise.cta_click` — on-ramp from loyalist → applicant
     *   - `mothership.first_order` — applicant → franchisee_self_use (婕樂纖後台首單成立)
     *   - `mothership.consultation_submitted` — visitor/loyalist → applicant (提交諮詢表單)
     *   - `academy.operator_portal_click` — franchisee_self_use → franchisee_active
     *     (TODO ADR-008 §6.1 Phase D：仙女學院前端發；朵朵 backend 暫不接收，先列入轉接清單)
     *
     * 觀察類事件（app.opened / franchise.cta_view）不在此 — 不能 thrash cache。
     *
     * @var list<string>
     */
    private const LIFECYCLE_TRIGGERING_EVENTS = [
        'engagement.deep',
        'franchise.cta_click',
        'mothership.first_order',
        'mothership.consultation_submitted',
        'academy.operator_portal_click',
    ];

    /**
     * Events explicitly dropped (no-op) because ADR-008 retired them.
     *
     *   - `academy.training_progress` — ADR-003 §2.4 仙女學院 3.5hr 線上必修系統訊號。
     *     ADR-008 §3.2 確認此訊號源作廢（3.5hr 訓練是婕樂纖人工 Zoom 課，系統不碰）。
     *     朵朵從未實作此 event，但保留 deny-list 防止其他 App 誤用。
     *
     * @var list<string>
     */
    private const DROPPED_EVENTS = [
        'academy.training_progress',
    ];

    public function __construct(
        private readonly LifecycleClient $lifecycle,
    ) {}

    /**
     * Publish a conversion event for a given pandora_user_uuid.
     *
     * @param  string  $eventType  ADR-003 §2.3 標準事件名（app.opened / engagement.deep / franchise.cta_view / franchise.cta_click 等）
     * @param  array<string, mixed>  $payload  Event-specific extra data
     */
    public function publish(
        string $pandoraUserUuid,
        string $eventType,
        array $payload = [],
        ?CarbonInterface $occurredAt = null,
    ): void {
        if (in_array($eventType, self::DROPPED_EVENTS, true)) {
            // ADR-008 §3.2: explicitly retired events. Noop silently so callers
            // that haven't been refactored don't break, but the event never
            // reaches py-service.
            Log::info('[Conversion] dropping retired event (ADR-008)', [
                'event_type' => $eventType,
                'uuid' => $pandoraUserUuid,
            ]);

            return;
        }

        if (! $this->isEnabled()) {
            Log::debug('[Conversion] publisher disabled (no base_url configured); skipping', [
                'event_type' => $eventType,
                'uuid' => $pandoraUserUuid,
            ]);

            return;
        }

        if ($pandoraUserUuid === '') {
            Log::warning('[Conversion] empty pandora_user_uuid, dropping event', [
                'event_type' => $eventType,
            ]);

            return;
        }

        $body = [
            'pandora_user_uuid' => $pandoraUserUuid,
            'app_id' => (string) config('services.pandora_conversion.app_id', 'doudou'),
            'event_type' => $eventType,
            // payload as array; Laravel Http will JSON-encode {} for empty.
            // Stripping nulls keeps the wire payload tight.
            'payload' => array_filter($payload, fn ($v) => $v !== null),
            'occurred_at' => ($occurredAt ?? Carbon::now())->toIso8601String(),
        ];

        // Note: we intentionally do NOT chain `->afterCommit()` here. The events
        // we care about (app.opened / engagement.deep / franchise.cta_*) are
        // independent observations rather than transactional outcomes — over-
        // firing on a rolled-back checkin is far cheaper than under-firing on a
        // successful one. py-service is also idempotent enough to absorb the
        // occasional duplicate.
        PublishConversionEventJob::dispatch($body);

        // Cache-bust lifecycle for events that may trigger a stage transition on
        // py-service. We don't know exactly when py-service will evaluate the
        // rule, but the next bootstrap call will re-fetch instead of serving a
        // stale 1h-cached value. See LifecycleClient::forget() docblock.
        if (in_array($eventType, self::LIFECYCLE_TRIGGERING_EVENTS, true)) {
            $this->lifecycle->forget($pandoraUserUuid);
        }
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

        $base = rtrim((string) config('services.pandora_conversion.base_url'), '/');
        $secret = (string) config('services.pandora_conversion.shared_secret');
        $timeout = (int) config('services.pandora_conversion.timeout', 5);

        $response = Http::withHeaders([
            'X-Internal-Secret' => $secret,
            'Accept' => 'application/json',
        ])
            ->timeout($timeout)
            ->retry(2, 200, throw: false)
            ->post($base.'/api/v1/internal/events', $body);

        if (! $response->successful()) {
            // Throw so the queue worker retries the job (with backoff).
            throw new \RuntimeException(sprintf(
                'conversion publish failed: status=%d body=%s',
                $response->status(),
                substr((string) $response->body(), 0, 200),
            ));
        }
    }

    public function isEnabled(): bool
    {
        $base = (string) config('services.pandora_conversion.base_url');
        $secret = (string) config('services.pandora_conversion.shared_secret');

        return $base !== '' && $secret !== '';
    }
}
