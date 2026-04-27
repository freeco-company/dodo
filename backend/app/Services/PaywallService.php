<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Translated (slimmed) from ai-game/src/services/paywall.ts.
 *
 * Currently only logs paywall events to paywall_events table. Pricing /
 * content fetching lives in AppConfigService (key: 'paywall'); this class
 * focuses on event ingestion. UI fetches view data via /api/bootstrap.
 */
class PaywallService
{
    public const KINDS = ['shown', 'dismissed', 'cta_clicked', 'converted'];

    /** @param array<string,mixed> $properties */
    public function logEvent(User $user, string $kind, ?string $trigger, array $properties = []): void
    {
        DB::table('paywall_events')->insert([
            'user_id' => $user->id,
            'event_kind' => $kind,
            'trigger' => $trigger,
            'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);
    }
}
