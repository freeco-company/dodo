<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/calendar?days=N — habit tracker heatmap data.
 * Slimmed port of ai-game/src/services/calendar.ts.
 */
class CalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $days = max(1, min(180, (int) $request->query('days', 30)));

        $today = Carbon::today();
        $start = $today->copy()->subDays($days - 1);

        $rows = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereBetween('date', [$start->toDateString(), $today->toDateString()])
            ->get(['date', 'total_score', 'meals_logged'])
            ->keyBy(fn ($r) => (string) $r->date);

        $cells = [];
        $perfect = 0;
        $logged = 0;
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $r = $rows->get($d);
            $score = $r ? (int) $r->total_score : 0;
            $meals = $r ? (int) $r->meals_logged : 0;
            $tier = $this->tierOf($score, $meals);
            $cells[] = [
                'date' => $d,
                'score' => $score,
                'tier' => $tier,
                'meals_logged' => $meals,
                'is_today' => $d === $today->toDateString(),
            ];
            if ($tier === 4) {
                $perfect++;
            }
            if ($tier > 0) {
                $logged++;
            }
        }

        // Current streak: walk back from today, stop on first non-logged day
        $currentStreak = 0;
        for ($i = count($cells) - 1; $i >= 0; $i--) {
            if ($cells[$i]['tier'] > 0) {
                $currentStreak++;
            } else {
                break;
            }
        }

        return response()->json([
            'days' => $cells,
            'today' => $today->toDateString(),
            'stats' => [
                'total_days' => $days,
                'perfect_days' => $perfect,
                'logged_days' => $logged,
                'current_streak' => $currentStreak,
            ],
        ]);
    }

    private function tierOf(int $score, int $meals): int
    {
        if ($meals === 0) {
            return 0;
        }
        if ($score >= 85) {
            return 4;
        }
        if ($score >= 70) {
            return 3;
        }
        if ($score >= 50) {
            return 2;
        }

        return 1;
    }
}
