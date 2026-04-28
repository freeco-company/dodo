<?php

namespace App\Services\Conversion;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 朵朵 admin → py-service `GET /api/v1/funnel/metrics` 客戶端（ADR-008 §2.2）。
 *
 * Lifecycle stages（ADR-008 兩段漏斗，5 stages）：
 *   visitor → loyalist → applicant → franchisee_self_use → franchisee_active
 *
 * 設計決策：
 *
 *   1) Auth：HMAC `X-Internal-Secret`（沿用 ConversionEventPublisher 同一把 secret）。
 *      這個端點在 internal network，secret 不對外暴露。
 *
 *   2) Failure mode：5xx / connection error → log + 回傳 empty stages（不爆 admin 頁）。
 *      漏斗 dashboard 是觀察類面板，缺資料總比讓整個 panel 503 好。
 *
 *   3) base_url 未設 → 回傳 stub fixture，讓 Phase A 開發者在沒部署 py-service 時
 *      也能在 admin 看到 layout。production 設了 base_url 就一定打真實 endpoint。
 *
 *   4) 不在這層做 cache。Widget 自己用 1h cache 包住 fetch()。
 *
 *   5) 與 py-service 部署順序：若 py-service 仍回舊 stage 名（registered/engaged/franchisee），
 *      normalizeStages() 會把那些 key 直接丟掉（unknown），新 stages count = 0。
 *      Admin Widget 顯示 0 但不炸；merge 順序自由。
 */
class FunnelMetricsClient
{
    public const STAGES = [
        'visitor',
        'loyalist',
        'applicant',
        'franchisee_self_use',
        'franchisee_active',
    ];

    /**
     * @return array{stages: array<string, int>, source: string, fetched_at: string}
     */
    public function fetch(): array
    {
        if (! $this->isConfigured()) {
            return $this->stubFixture();
        }

        $base = rtrim((string) config('services.pandora_conversion.base_url'), '/');
        $secret = (string) config('services.pandora_conversion.shared_secret');
        $timeout = (int) config('services.pandora_conversion.timeout', 5);

        try {
            $response = Http::withHeaders([
                'X-Internal-Secret' => $secret,
                'Accept' => 'application/json',
            ])
                ->timeout($timeout)
                ->get($base.'/api/v1/funnel/metrics');
        } catch (ConnectionException $e) {
            Log::warning('[FunnelMetrics] connection failed', ['error' => $e->getMessage()]);

            return $this->emptyResponse('error');
        }

        if (! $response->successful()) {
            Log::warning('[FunnelMetrics] non-2xx response', [
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 200),
            ]);

            return $this->emptyResponse('error');
        }

        $data = (array) $response->json();
        $stages = (array) ($data['stages'] ?? []);

        return [
            'stages' => $this->normalizeStages($stages),
            'source' => 'live',
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    public function isConfigured(): bool
    {
        $base = (string) config('services.pandora_conversion.base_url');
        $secret = (string) config('services.pandora_conversion.shared_secret');

        return $base !== '' && $secret !== '';
    }

    /**
     * Dev / Phase A fallback. Numbers chosen so each stage->stage conversion
     * rate is recognisable but not artificially round.
     *
     * @return array{stages: array<string, int>, source: string, fetched_at: string}
     */
    private function stubFixture(): array
    {
        return [
            'stages' => [
                'visitor' => 1000,
                'loyalist' => 95,
                'applicant' => 18,
                'franchisee_self_use' => 12,
                'franchisee_active' => 3,
            ],
            'source' => 'stub',
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{stages: array<string, int>, source: string, fetched_at: string}
     */
    private function emptyResponse(string $source): array
    {
        return [
            'stages' => array_fill_keys(self::STAGES, 0),
            'source' => $source,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Coerce values to int, fill missing stages with 0, preserve canonical order.
     *
     * @param  array<string, mixed>  $stages
     * @return array<string, int>
     */
    private function normalizeStages(array $stages): array
    {
        $out = [];
        foreach (self::STAGES as $stage) {
            $out[$stage] = (int) ($stages[$stage] ?? 0);
        }

        return $out;
    }
}
