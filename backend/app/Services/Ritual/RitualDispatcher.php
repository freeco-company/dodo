<?php

namespace App\Services\Ritual;

use App\Models\RitualEvent;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * SPEC-progress-ritual-v1 PR #1 — fire-once ritual dispatcher.
 *
 * Idempotent via idempotency_key — same outfit_id / streak_count / month
 * never re-fires. Frontend pulls unread events on home open + first-time
 * chat tab to surface fullscreen ceremony.
 *
 * Per-user cap: 3 active surfaced rituals at a time (older auto-shoved
 * to historical). PR #2 adds the cleanup schedule.
 */
class RitualDispatcher
{
    public function dispatch(User $user, string $ritualKey, string $idempotencyKey, array $payload, ?CarbonImmutable $now = null): ?RitualEvent
    {
        $existing = RitualEvent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return null;
        }

        return RitualEvent::create([
            'user_id' => $user->id,
            'ritual_key' => $ritualKey,
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
            'triggered_at' => $now ?? CarbonImmutable::now(),
        ]);
    }

    public function markSeen(RitualEvent $event): void
    {
        if ($event->seen_at === null) {
            $event->update(['seen_at' => CarbonImmutable::now()]);
        }
    }

    public function markShared(RitualEvent $event): void
    {
        if ($event->shared_at === null) {
            $event->update(['shared_at' => CarbonImmutable::now()]);
        }
    }
}
