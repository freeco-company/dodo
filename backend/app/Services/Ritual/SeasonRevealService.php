<?php

namespace App\Services\Ritual;

use App\Models\RitualEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * SPEC-progress-ritual-v1 PR #9 — first-open-of-day season reveal trigger.
 *
 * Called from BootstrapController on every app open. For each currently
 * active release (release_at <= now < expires_at), dispatch a season reveal
 * ritual. RitualDispatcher's idempotency_key (`season_reveal:{user}:{rel_id}`)
 * dedups → user only sees the fullscreen once per release per device-session.
 *
 * Fail-soft: catches exceptions; bootstrap never breaks because of this.
 */
class SeasonRevealService
{
    public function __construct(
        private readonly SeasonalReleaseCatalog $catalog,
        private readonly RitualDispatcher $dispatcher,
    ) {}

    public function checkAndFireForUser(User $user, ?CarbonImmutable $now = null): int
    {
        $fired = 0;
        try {
            foreach ($this->catalog->currentlyActive($now) as $release) {
                $event = $this->dispatcher->dispatch(
                    $user,
                    RitualEvent::KEY_SEASON_REVEAL,
                    "season_reveal:{$user->id}:{$release['id']}",
                    [
                        'release_id' => $release['id'],
                        'season_name' => $release['season_name'],
                        'outfit_codes' => $release['outfit_codes'],
                        'release_at' => $release['release_at']->toIso8601String(),
                        'expires_at' => $release['expires_at']->toIso8601String(),
                    ],
                );
                if ($event !== null) {
                    $fired++;
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return $fired;
    }
}
