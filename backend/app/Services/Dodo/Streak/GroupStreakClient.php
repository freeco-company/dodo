<?php

namespace App\Services\Dodo\Streak;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cross-App master streak client — reads집団 daily-login streak from py-service
 * (`pandora-core-conversion`) for overlay display on top of meal's per-App toast.
 *
 * Phase 5B (frontend overlay) consumer of:
 *   GET {base}/api/v1/internal/group-streak/{user_uuid}
 *   header X-Internal-Secret: <shared>
 *
 * Auth: same shared-secret pattern as {@see \App\Services\Gamification\GamificationPublisher}
 * — py-service has a single `INTERNAL_SHARED_SECRET` so we reuse it (with override
 * via `GROUP_STREAK_HMAC_SECRET` for split-deploy flexibility).
 *
 * Reliability:
 *   - 5s timeout, fail-soft (returns null, never throws) — group streak is
 *     decoration not core; py-service flake must not break /api/streak/today.
 *   - 30s per-uuid cache absorbs any toast-driven re-poll.
 *   - Disabled (returns null silently) when base_url / secret unset, so dev
 *     environments without py-service Just Work.
 *
 * @see /Users/chris/freeco/pandora/pandora-core-conversion/app/gamification/routes.py
 *      `GET /api/v1/internal/group-streak/{uuid}` — response shape: user_uuid,
 *      current_streak, longest_streak, last_login_date, last_seen_app, today_in_streak.
 */
class GroupStreakClient
{
    private const CACHE_TTL_SECONDS = 30;
    private const TIMEOUT_SECONDS = 5;

    /**
     * Fetch master cross-App streak snapshot for a given Pandora Core uuid.
     *
     * @return array{
     *     current_streak: int,
     *     longest_streak: int,
     *     today_in_streak: bool,
     *     last_login_date: ?string
     * }|null  null on disabled / network-fail / non-2xx — caller renders null gracefully.
     */
    public function fetch(string $pandoraUserUuid): ?array
    {
        if ($pandoraUserUuid === '' || ! $this->isEnabled()) {
            return null;
        }

        $cacheKey = 'group_streak:'.$pandoraUserUuid;

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($pandoraUserUuid): ?array {
            return $this->fetchRemote($pandoraUserUuid);
        });
    }

    /**
     * @return array{current_streak: int, longest_streak: int, today_in_streak: bool, last_login_date: ?string}|null
     */
    private function fetchRemote(string $pandoraUserUuid): ?array
    {
        $base = rtrim((string) config('services.group_streak.base_url'), '/');
        $secret = (string) config('services.group_streak.shared_secret');
        $timeout = (int) config('services.group_streak.timeout', self::TIMEOUT_SECONDS);

        try {
            $response = Http::withHeaders([
                'X-Internal-Secret' => $secret,
                'Accept' => 'application/json',
            ])
                ->timeout($timeout)
                ->get($base.'/api/v1/internal/group-streak/'.$pandoraUserUuid);

            if (! $response->successful()) {
                Log::info('[GroupStreak] non-2xx, fail-soft', [
                    'uuid' => $pandoraUserUuid,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();
            if (! is_array($data)) {
                return null;
            }

            return [
                'current_streak' => (int) ($data['current_streak'] ?? 0),
                'longest_streak' => (int) ($data['longest_streak'] ?? 0),
                'today_in_streak' => (bool) ($data['today_in_streak'] ?? false),
                'last_login_date' => isset($data['last_login_date']) && is_string($data['last_login_date'])
                    ? $data['last_login_date']
                    : null,
            ];
        } catch (Throwable $e) {
            Log::info('[GroupStreak] fetch failed, fail-soft', [
                'uuid' => $pandoraUserUuid,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function isEnabled(): bool
    {
        $base = (string) config('services.group_streak.base_url');
        $secret = (string) config('services.group_streak.shared_secret');

        return $base !== '' && $secret !== '';
    }
}
