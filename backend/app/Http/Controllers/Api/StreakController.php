<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Dodo\Streak\DailyLoginStreakService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SPEC-daily-login-streak — read-only streak endpoint.
 *
 * GET /api/streak/today — returns current state plus a fresh recordLogin()
 * summary so the FE can decide whether to flash a toast (is_first_today /
 * is_milestone) on app boot, in one round-trip.
 */
class StreakController extends Controller
{
    public function __construct(
        private readonly DailyLoginStreakService $service,
    ) {}

    public function today(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $recorded = $this->service->recordLogin($user);
        $snapshot = $this->service->snapshot($user);

        return response()->json([
            'current_streak' => $recorded['streak'],
            'longest_streak' => max($recorded['longest_streak'], $snapshot['longest_streak']),
            'is_first_today' => $recorded['is_first_today'],
            'is_milestone' => $recorded['is_milestone'],
            'milestone_label' => $recorded['milestone_label'],
            'today_date' => $recorded['today_date'],
        ]);
    }
}
