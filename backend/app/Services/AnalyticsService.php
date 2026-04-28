<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Translated (slimmed) from ai-game/src/services/analytics.ts.
 *
 * Always writes to analytics_events for replay/audit. flush() forwards
 * un-sent rows to PostHog when POSTHOG_API_KEY is configured.
 *
 * Phase E note: PostHog forwarding is now wired (HTTP /batch/), but kept
 * fail-soft — a 4xx/5xx response logs a warning and leaves rows
 * `sent_to_provider=false` for the next attempt. Missing key → no-op (0).
 */
class AnalyticsService
{
    /** @param array<string,mixed>|null $properties */
    public function track(?User $user, string $event, ?array $properties = null): void
    {
        DB::table('analytics_events')->insert([
            'user_id' => $user?->id,
            // Phase D Wave 1 dual-write: 從 legacy user 抄 uuid（model attribute 已 hydrate）
            'pandora_user_uuid' => $user?->pandora_user_uuid,
            'event' => $event,
            'properties' => $properties ? json_encode($properties, JSON_UNESCAPED_UNICODE) : null,
            'sent_to_provider' => false,
            'created_at' => now(),
        ]);
    }

    /**
     * Forward un-sent analytics rows to PostHog. Returns the number of rows
     * marked sent_to_provider=true.
     *
     * Strategy: pull ≤ batchSize rows, build a PostHog `/batch/` payload,
     * POST it. On 2xx, mark all rows as sent in a single UPDATE. On
     * non-2xx or transport error, leave them — next call will retry.
     */
    public function flush(int $batchSize = 200): int
    {
        $apiKey = (string) config('services.posthog.api_key');
        if ($apiKey === '') {
            Log::info('[analytics] POSTHOG_API_KEY unset → flush is no-op');

            return 0;
        }

        $rows = DB::table('analytics_events')
            ->where('sent_to_provider', false)
            ->orderBy('id')
            ->limit($batchSize)
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $batch = [];
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->id;
            $properties = [];
            if ($row->properties) {
                $decoded = json_decode($row->properties, true);
                if (is_array($decoded)) {
                    $properties = $decoded;
                }
            }
            $batch[] = [
                'event' => $row->event,
                'distinct_id' => (string) ($row->pandora_user_uuid ?? $row->user_id ?? 'anon'),
                'properties' => $properties,
                'timestamp' => $row->created_at,
            ];
        }

        $host = (string) config('services.posthog.host');
        try {
            $response = Http::asJson()
                ->timeout(5)
                ->post(rtrim($host, '/').'/batch/', [
                    'api_key' => $apiKey,
                    'batch' => $batch,
                ]);
        } catch (\Throwable $e) {
            Log::warning('[analytics] PostHog flush transport error', ['error' => $e->getMessage()]);

            return 0;
        }

        if (! $response->successful()) {
            Log::warning('[analytics] PostHog flush non-2xx', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return 0;
        }

        DB::table('analytics_events')->whereIn('id', $ids)->update(['sent_to_provider' => true]);

        return count($ids);
    }
}
