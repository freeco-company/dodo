<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Translated (slimmed) from ai-game/src/services/analytics.ts.
 *
 * Always writes to analytics_events for replay/audit.
 *
 * TODO: PostHog forwarding. The TS version forwards capture() to PostHog when
 * POSTHOG_API_KEY is set. Skipped this version — flushPending() leaves rows
 * with sent_to_provider=false until the integration lands. Once PostHog is
 * wired, /api/admin/analytics/flush will batch-resend.
 */
class AnalyticsService
{
    /** @param array<string,mixed>|null $properties */
    public function track(?User $user, string $event, ?array $properties = null): void
    {
        DB::table('analytics_events')->insert([
            'user_id' => $user?->id,
            'event' => $event,
            'properties' => $properties ? json_encode($properties, JSON_UNESCAPED_UNICODE) : null,
            'sent_to_provider' => false,
            'created_at' => now(),
        ]);
    }

    /**
     * Resend events not yet forwarded to upstream provider.
     * TODO: actual PostHog HTTP call. Currently a no-op that logs intent
     * so callers (admin endpoint) get a deterministic 0 response.
     */
    public function flush(int $batchSize = 200): int
    {
        $apiKey = env('POSTHOG_API_KEY');
        if (! $apiKey) {
            Log::info('[analytics] POSTHOG_API_KEY unset → flush is no-op');
            return 0;
        }

        // Provider integration not wired yet (PostHog). Returning 0 keeps the
        // contract honest until we can forward + mark sent_to_provider=true.
        Log::warning('[analytics] PostHog provider not implemented yet — flush deferred');
        return 0;
    }
}
