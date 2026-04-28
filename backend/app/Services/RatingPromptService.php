<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Translated (slimmed) from ai-game/src/services/rating_prompt.ts.
 *
 * Just logs rating prompt events. The "should we show it?" decision relies on
 * users.* columns the original TS service used (rating_prompt_shown_at,
 * dismissed_count) which aren't migrated yet — that gating logic will land
 * in a follow-up once those columns exist.
 */
class RatingPromptService
{
    public const KINDS = ['shown', 'dismissed', 'rated', 'cta_app_open'];

    public function log(User $user, string $kind): void
    {
        DB::table('rating_prompt_events')->insert([
            'user_id' => $user->id,
            // Phase D Wave 1 dual-write
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'event_kind' => $kind,
            'created_at' => now(),
        ]);
    }
}
