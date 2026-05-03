<?php

namespace App\Services\Gamification;

use App\Models\RitualEvent;
use App\Models\User;
use App\Services\Ritual\RitualDispatcher;

/**
 * Apply a `gamification.outfit_unlocked` webhook to the local
 * `users.outfits_owned` JSON column so OutfitController.index can show the
 * unlocked wardrobe immediately after a level-up — without requiring the
 * client to re-derive unlock state from local level (which Phase B.3 is
 * trying to retire as the source of truth).
 *
 * Idempotent: codes already in `outfits_owned` are skipped, so replay or
 * double-fire is safe. Returns the count of newly merged codes.
 */
class OutfitMirror
{
    public function __construct(
        private readonly RitualDispatcher $rituals,
    ) {}

    /**
     * SPEC-progress-ritual-v1 PR #6 — outfits whose unlock should fire a
     * fullscreen ceremony (vs the silent sparkle for ordinary day-quest unlocks).
     * Override-safe: keys not in this set still mirror to outfits_owned but
     * skip the ritual fire.
     */
    private const RARE_OUTFIT_CODES = [
        'winter_scarf', 'sakura_kimono', 'summer_yukata', 'autumn_maple',
        'mid_autumn', 'christmas', 'lunar_new_year', 'qixi',
    ];

    /**
     * @param  array<string, mixed>  $payload  Webhook payload from py-service.
     *                                         Expected keys: codes (list<string>),
     *                                         awarded_via, trigger_level, occurred_at.
     */
    public function applyUnlocked(string $pandoraUserUuid, array $payload): int
    {
        if ($pandoraUserUuid === '') {
            return 0;
        }
        $codes = $payload['codes'] ?? null;
        if (! is_array($codes) || $codes === []) {
            return 0;
        }

        $user = User::where('pandora_user_uuid', $pandoraUserUuid)->first();
        if ($user === null) {
            return 0;
        }

        $owned = (array) ($user->outfits_owned ?? ['none']);
        $added = 0;
        foreach ($codes as $code) {
            if (! is_string($code) || $code === '') {
                continue;
            }
            if (! in_array($code, $owned, true)) {
                $owned[] = $code;
                $added++;
            }
        }

        if ($added > 0) {
            $user->fill(['outfits_owned' => $owned]);
            $user->save();
        }

        // SPEC-progress-ritual-v1 PR #6 — fire fullscreen ritual for rare
        // outfits only. Idempotent via ritual idempotency_key.
        foreach ($codes as $code) {
            if (! is_string($code) || ! in_array($code, self::RARE_OUTFIT_CODES, true)) {
                continue;
            }
            $this->rituals->dispatch(
                $user,
                RitualEvent::KEY_OUTFIT_UNLOCK_FULLSCREEN,
                "outfit_unlock:{$user->id}:{$code}",
                ['outfit_code' => $code, 'outfit_name' => $code, 'unlocked_via' => $payload['awarded_via'] ?? null],
            );
        }

        return $added;
    }
}
