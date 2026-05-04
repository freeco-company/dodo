<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Dodo\Streak\DailyLoginStreakService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * SPEC-daily-login-streak — record per-request login streak.
 *
 * Runs after auth (so $request->user() is set). Idempotent within a calendar
 * day (Asia/Taipei) — first authenticated hit of the day bumps the streak;
 * subsequent hits are no-op. Result is attached as response header
 * `X-Streak` (JSON) so frontend can show toast without an extra round-trip,
 * and stashed on request attribute `daily_streak` for downstream code.
 *
 * Fail-soft: any exception is logged and swallowed — never block a request
 * because of streak bookkeeping.
 */
class RecordDailyStreak
{
    public function __construct(
        private readonly DailyLoginStreakService $service,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $user = $request->user();
        if (! $user instanceof User) {
            return $response;
        }

        try {
            $result = $this->service->recordLogin($user);
            $request->attributes->set('daily_streak', $result);

            // Header is JSON-encoded so frontend can `JSON.parse(headers.get('X-Streak'))`.
            $response->headers->set('X-Streak', (string) json_encode($result, JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            Log::warning('[RecordDailyStreak] failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }
}
