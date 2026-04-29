<?php

namespace App\Services\Gamification;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Apply a `gamification.achievement_awarded` webhook to the local
 * `achievements` table mirror so AchievementController.index can show the
 * badge in the user's pokedex/dashboard.
 *
 * Idempotent on (user_id, achievement_key) — composite unique enforces it
 * at the DB layer, we also short-circuit on the SELECT to avoid a wasted
 * INSERT/rollback pair.
 *
 * Server is the authority for *which* achievements exist (see py-service
 * ACHIEVEMENT_CATALOG); this just keeps a local mirror of who-has-what so
 * the alignment with existing index endpoint stays consistent.
 */
class AchievementMirror
{
    /**
     * @param  array<string, mixed>  $payload  Webhook payload from py-service
     *                                         (achievement_awarded event).
     *                                         Expected keys: code, name, tier,
     *                                         source_app, occurred_at.
     */
    public function applyAwarded(string $pandoraUserUuid, array $payload): bool
    {
        if ($pandoraUserUuid === '') {
            return false;
        }
        $code = (string) ($payload['code'] ?? '');
        if ($code === '') {
            return false;
        }
        $name = (string) ($payload['name'] ?? $code);

        $user = User::where('pandora_user_uuid', $pandoraUserUuid)->first();
        if ($user === null) {
            // No mirror row yet — silently drop. AchievementController.index
            // wouldn't have anywhere to surface the badge anyway.
            return false;
        }

        $existing = Achievement::where('user_id', $user->id)
            ->where('achievement_key', $code)
            ->first();
        if ($existing !== null) {
            return false;
        }

        $occurredAt = $payload['occurred_at'] ?? null;
        $unlockedAt = is_string($occurredAt) ? Carbon::parse($occurredAt) : Carbon::now();

        Achievement::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $pandoraUserUuid,
            'achievement_key' => $code,
            'achievement_name' => $name,
            'unlocked_at' => $unlockedAt,
        ]);

        return true;
    }
}
