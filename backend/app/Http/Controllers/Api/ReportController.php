<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyLog;
use App\Models\Meal;
use App\Models\WeeklyReport;
use App\Services\Gamification\GamificationPublisher;
use App\Services\WeeklyReportService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/reports/weekly/{date} — legacy "letter" endpoint.
 *
 * Slimmed port of ai-game/src/services/reports.ts. Surfaces deterministic
 * stats (avg score, perfect/logged days, weight delta, top foods); the
 * `letter` field stays empty so legacy UI clients fall back to a template.
 *
 * The richer SPEC-04 weekly report (with fasting + health aggregates +
 * 朵朵 AI narrative for paid users) lives at /api/reports/weekly/current
 * and the by-date / history / shared sibling endpoints in this same
 * controller — see currentWeekly() / richWeeklyByDate() below.
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly GamificationPublisher $gamification,
        private readonly WeeklyReportService $weeklyService,
    ) {}

    /**
     * SPEC-04 §3 — current week's rich report (cached snapshot or freshly
     * aggregated; idempotent regenerate).
     */
    public function currentWeekly(Request $request): JsonResponse
    {
        return response()->json($this->weeklyService->generate($request->user()));
    }

    /**
     * SPEC-04 §3 — past report by week_start (YYYY-MM-DD, must be a Sunday;
     * service will snap to start-of-week regardless).
     */
    public function richWeeklyByDate(Request $request, string $weekStart): JsonResponse
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart)) {
            return response()->json(['message' => 'week_start must be YYYY-MM-DD'], 422);
        }
        $start = CarbonImmutable::parse($weekStart, 'Asia/Taipei');

        return response()->json($this->weeklyService->generate($request->user(), $start));
    }

    public function weeklyHistory(Request $request): JsonResponse
    {
        $weeks = (int) $request->integer('weeks', 12);
        $weeks = max(1, min(52, $weeks));

        return response()->json([
            'data' => $this->weeklyService->history($request->user(), $weeks),
        ]);
    }

    public function recordShared(Request $request, int $id): JsonResponse
    {
        $report = WeeklyReport::query()
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();
        if ($report === null) {
            return response()->json(['message' => 'Report not found'], 404);
        }
        $count = $this->weeklyService->recordShared($report);

        return response()->json(['shared_count' => $count]);
    }

    public function weekly(Request $request, string $date): JsonResponse
    {
        $user = $request->user();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['message' => 'date must be YYYY-MM-DD'], 422);
        }

        $weekStart = Carbon::parse($date)->startOfWeek(Carbon::MONDAY);
        $weekEnd = $weekStart->copy()->addDays(6);

        // Try cached report first
        $cached = WeeklyReport::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        $logs = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('date')
            ->get(['date', 'total_score', 'meals_logged']);

        // `date` column is cast to Carbon — coerce to YYYY-MM-DD for stable
        // keying against $weekStart->addDays($i)->toDateString() below.
        $byDate = $logs->keyBy(fn ($r) => $r->date instanceof \Carbon\CarbonInterface
            ? $r->date->toDateString()
            : Carbon::parse((string) $r->date)->toDateString());
        $dailyScores = [];
        $dailyHasLog = [];
        $perfect = 0;
        $loggedDays = 0;
        for ($i = 0; $i < 7; $i++) {
            $d = $weekStart->copy()->addDays($i)->toDateString();
            $r = $byDate->get($d);
            $score = $r ? (int) $r->total_score : 0;
            $meals = $r ? (int) $r->meals_logged : 0;
            $dailyScores[] = $score;
            $dailyHasLog[] = $meals > 0;
            if ($meals > 0) {
                $loggedDays++;
            }
            if ($score >= 85 && $meals > 0) {
                $perfect++;
            }
        }
        $avg = $loggedDays > 0 ? (int) round(array_sum($dailyScores) / $loggedDays) : 0;

        $topFoods = Meal::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereNotNull('food_name')
            ->selectRaw('food_name, COUNT(*) as count')
            ->groupBy('food_name')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['name' => (string) $r->food_name, 'count' => (int) ($r->count ?? 0)])
            ->values();

        $mealsTotal = Meal::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->count();

        // ADR-009 §3 / catalog §3.1 — fire meal.weekly_review_read once per
        // (user, week) when the user has enough data for a meaningful review
        // (avoid crediting empty weeks). Server idempotency_key uses week_start.
        $uuid = is_string($user->pandora_user_uuid) ? $user->pandora_user_uuid : '';
        if ($uuid !== '' && $loggedDays >= 3) {
            $this->gamification->publish(
                $uuid,
                'meal.weekly_review_read',
                "meal.weekly_review_read.{$uuid}.".$weekStart->toDateString(),
                ['week_start' => $weekStart->toDateString()],
            );
        }

        return response()->json([
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'avg_score' => $avg,
            'daily_scores' => $dailyScores,
            'daily_has_log' => $dailyHasLog,
            'perfect_days' => $perfect,
            'logged_days' => $loggedDays,
            'meals_total' => $mealsTotal,
            'top_foods' => $topFoods,
            'has_enough_data' => $loggedDays >= 3,
            'letter' => $cached !== null ? (string) ($cached->letter_content ?? '') : '',
            'cached' => (bool) $cached,
        ]);
    }
}
