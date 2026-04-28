<?php

namespace App\Services\Conversion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 朵朵 → py-service `GET /api/v1/users/{uuid}/lifecycle` 客戶端（ADR-003 §2.2 / §2.3）。
 *
 * 取單一 user 的 lifecycle stage，給 BootstrapController 決定是否秀「諮詢加盟」CTA。
 *
 * Lifecycle stages（與 FunnelMetricsClient::STAGES 對齊）：
 *   visitor → registered → engaged → loyalist → applicant → franchisee
 *
 * 設計決策：
 *
 *   1) Auth：HMAC `X-Internal-Secret`（沿用 ConversionEventPublisher 同一把 secret）。
 *      理由與 FunnelMetricsClient 相同 — internal network、不對外暴露。
 *
 *   2) Failure mode：5xx / connection error / base_url 未設 → 一律 fallback 為 'visitor'
 *      （最低 stage）。理由：CTA 顯示是「漏斗推進」，不確定的人寧可 false negative
 *      也不要 false positive 騷擾使用者；公平交易法相關文案紅線（dodo CLAUDE.md
 *      §2 / ADR-003 §6）也偏好保守。
 *
 *   3) Cache：1h TTL（key: `lifecycle:{uuid}`）。
 *      lifecycle 變動是偏低頻事件（一個 user 從 engaged 升 loyalist 通常週/月級），
 *      1h cache 在 SLO（CTA 出現延遲 ≤ 1h）跟 py-service 流量保護間取平衡。
 *      TODO：v2 加 py-service webhook 主動清 cache（ADR-003 §2.4 stage_change event），
 *      v1 純靠 TTL 過期。
 *
 *   4) 不在 client 層做 stage→cta 的 mapping，那是 BootstrapController 的職責
 *      （讓 client 純粹是 transport，business rule 集中在 controller / service）。
 */
class LifecycleClient
{
    public const DEFAULT_STAGE = 'visitor';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * 取單一 user 的 lifecycle stage。失敗一律回 'visitor'。
     *
     * @param  bool  $bypassCache  true → 忽略 1h cache 直接打 py-service，並把新值寫回 cache。
     *                             用於剛 fire 過 lifecycle-相關 event（engagement.deep /
     *                             franchise.cta_click）後想看「升等了沒」的場景。預設 false。
     */
    public function getStatus(string $pandoraUserUuid, bool $bypassCache = false): string
    {
        if ($pandoraUserUuid === '') {
            return self::DEFAULT_STAGE;
        }

        if (! $this->isConfigured()) {
            return self::DEFAULT_STAGE;
        }

        if ($bypassCache) {
            // 強制重抓並覆寫 cache，這樣後續讀者也會拿到新值。
            $stage = $this->fetch($pandoraUserUuid);
            Cache::put($this->cacheKey($pandoraUserUuid), $stage, self::CACHE_TTL_SECONDS);

            return $stage;
        }

        return Cache::remember(
            $this->cacheKey($pandoraUserUuid),
            self::CACHE_TTL_SECONDS,
            fn () => $this->fetch($pandoraUserUuid),
        );
    }

    /**
     * 清除某個 user 的 lifecycle cache。
     *
     * 用途：fire 完 lifecycle-觸發類 event（engagement.deep / franchise.cta_click）後，
     * py-service 會在幾秒內 evaluate lifecycle rule 並可能 transition stage；
     * 我們不知道確切時間點，但「下次 bootstrap 時抓最新」就夠用 → 清 cache 即可。
     *
     * v2 計畫：py-service 直接 push stage_change webhook 過來再清，省一次 HTTP。
     */
    public function forget(string $pandoraUserUuid): void
    {
        if ($pandoraUserUuid === '') {
            return;
        }

        Cache::forget($this->cacheKey($pandoraUserUuid));
    }

    public function isConfigured(): bool
    {
        $base = (string) config('services.pandora_conversion.base_url');
        $secret = (string) config('services.pandora_conversion.shared_secret');

        return $base !== '' && $secret !== '';
    }

    /**
     * Cache key per-uuid, used by tests to flush.
     */
    public function cacheKey(string $pandoraUserUuid): string
    {
        return 'lifecycle:'.$pandoraUserUuid;
    }

    private function fetch(string $pandoraUserUuid): string
    {
        $base = rtrim((string) config('services.pandora_conversion.base_url'), '/');
        $secret = (string) config('services.pandora_conversion.shared_secret');
        $timeout = (int) config('services.pandora_conversion.timeout', 5);

        try {
            $response = Http::withHeaders([
                'X-Internal-Secret' => $secret,
                'Accept' => 'application/json',
            ])
                ->timeout($timeout)
                ->get($base.'/api/v1/users/'.urlencode($pandoraUserUuid).'/lifecycle');
        } catch (ConnectionException $e) {
            Log::warning('[Lifecycle] connection failed', [
                'uuid' => $pandoraUserUuid,
                'error' => $e->getMessage(),
            ]);

            return self::DEFAULT_STAGE;
        }

        if (! $response->successful()) {
            Log::warning('[Lifecycle] non-2xx response', [
                'uuid' => $pandoraUserUuid,
                'status' => $response->status(),
            ]);

            return self::DEFAULT_STAGE;
        }

        $data = (array) $response->json();
        $stage = (string) ($data['stage'] ?? $data['status'] ?? '');

        if (! in_array($stage, FunnelMetricsClient::STAGES, true)) {
            Log::warning('[Lifecycle] unknown stage from py-service, falling back', [
                'uuid' => $pandoraUserUuid,
                'stage' => $stage,
            ]);

            return self::DEFAULT_STAGE;
        }

        return $stage;
    }
}
