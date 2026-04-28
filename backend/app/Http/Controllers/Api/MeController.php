<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\DailyLog;
use App\Models\Meal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/me/* — current-user-scoped endpoints.
 *
 * Resolves the user from the sanctum bearer token (no uid in path).
 * Replaces legacy ai-game routes /user/:uid/dashboard and /user/:uid/settings.
 *
 * Dashboard contract is shaped for the existing frontend `loadDashboard()` in
 * `frontend/public/app.js` (audited 2026-04-28). The frontend reads:
 *   d.user.{avatar_animal, daily_calorie_target, equipped_outfit}
 *   d.today.{total_calories, total_protein_g, total_carbs_g, total_fat_g,
 *            total_score, water_ml, exercise_minutes, remaining_calories,
 *            meals[]}
 *   d.doudou.{level, level_name, mood, mood_phrase, xp, xp_next_level,
 *             xp_progress, streak, streak_shields, friendship}
 *   d.tasks[]
 *   d.achievements[]
 *
 * Legacy keys (`today.calories`, `today.protein_g`, `today.score`,
 * `progress.{level,xp,...}`) are kept for backward compat with existing tests
 * and any in-flight clients. See MeDashboardShapeTest for the contract lock.
 *
 * @todo Phase F: replace stubbed mood/mood_phrase/tasks with real
 *       AI-generated mood, daily quest aggregation, and macros from per-meal
 *       carbs/fat once those columns ship.
 */
class MeController extends Controller
{
    /**
     * Pool of friendly mood phrases shown in the home tab speech bubble.
     *
     * @todo Phase F: switch to AI-generated phrase keyed off recent
     *       behavior (streak, last meal score, quest completion).
     */
    private const MOOD_PHRASES = [
        '今天也要好好吃飯喔～',
        '一起加油，吃好每一餐！',
        '我相信你今天可以做到 ✨',
        '記得多喝水唷 💧',
        '小步前進就是大進步！',
        '你比昨天更棒了一點點～',
        '讓我們一起變健康吧！',
    ];

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today()->toDateString();

        // whereDate (not where) so the Carbon date-cast on DailyLog::date works
        // identically against SQLite (test) and MariaDB (prod). MariaDB still
        // uses the (user_id, date) index because DATE() on a DATE column is a
        // no-op the planner sees through.
        $daily = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', $today)
            ->first();

        $todayMeals = Meal::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereDate('date', $today)
            ->orderBy('id')
            ->get(['id', 'meal_type', 'food_name', 'calories', 'protein_g', 'meal_score']);

        $caloriesToday = (int) $todayMeals->sum('calories');
        $proteinToday = (float) $todayMeals->sum('protein_g');

        // Last 7 days score timeline for the heatmap strip
        $start = Carbon::today()->subDays(6)->toDateString();
        $last7 = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->whereBetween('date', [$start, $today])
            ->orderBy('date')
            ->get(['date', 'total_score', 'meals_logged'])
            ->map(fn ($r) => [
                'date' => (string) $r->date,
                'score' => (int) $r->total_score,
                'meals_logged' => (int) $r->meals_logged,
            ])->values();

        $achievements = Achievement::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->orderByDesc('unlocked_at')
            ->limit(10)
            ->get(['achievement_key', 'achievement_name', 'unlocked_at']);

        $level = (int) ($user->level ?? 1);
        $xp = (int) ($user->xp ?? 0);
        $streak = (int) ($user->current_streak ?? 0);
        $friendship = (int) ($user->friendship ?? 0);
        $shields = (int) ($user->streak_shields ?? 0);
        $calorieTarget = (int) ($user->daily_calorie_target ?? 1800);
        $todayScore = $daily ? (int) $daily->total_score : 0;

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'avatar_color' => $user->avatar_color,
                'avatar_species' => $user->avatar_species,
                'avatar_animal' => $user->avatar_animal,
                'equipped_outfit' => $user->equipped_outfit,
                'current_weight_kg' => $user->current_weight_kg,
                'target_weight_kg' => $user->target_weight_kg,
                'daily_calorie_target' => $calorieTarget,
                'daily_protein_target_g' => $user->daily_protein_target_g,
                'subscription_tier' => $user->membership_tier,
            ],
            'today' => [
                'date' => $today,
                // Legacy keys (pre-2026-04-28 contract) — keep for backward compat.
                'score' => $todayScore,
                'meals_logged' => $daily ? (int) $daily->meals_logged : 0,
                'calories' => $caloriesToday,
                'calories_target' => $calorieTarget,
                'protein_g' => round($proteinToday, 1),
                'protein_target_g' => (float) ($user->daily_protein_target_g ?? 80),
                // Frontend-expected keys (audited from app.js loadDashboard).
                'total_calories' => $caloriesToday,
                'total_protein_g' => round($proteinToday, 1),
                'total_carbs_g' => 0.0,        // @todo Phase F: sum meals.carbs_g once column ships
                'total_fat_g' => 0.0,          // @todo Phase F: sum meals.fat_g once column ships
                'total_fiber_g' => 0.0,        // @todo Phase F: sum meals.fiber_g once column ships
                'total_score' => $todayScore,
                'water_ml' => $daily ? (int) $daily->water_ml : 0,
                'exercise_minutes' => $daily ? (int) $daily->exercise_minutes : 0,
                'remaining_calories' => max(0, $calorieTarget - $caloriesToday),
                'meals' => $todayMeals,
            ],
            'progress' => [
                // Legacy block — kept until frontend stops reading it.
                'level' => $level,
                'xp' => $xp,
                'current_streak' => $streak,
                'longest_streak' => (int) ($user->longest_streak ?? 0),
                'friendship' => $friendship,
            ],
            'doudou' => [
                'level' => $level,
                'level_name' => $this->levelName($level),
                'mood' => 'happy',                      // @todo Phase F: AI-generated mood
                'mood_phrase' => $this->defaultMoodPhrase($user),
                'xp' => $xp,
                'xp_next_level' => $this->xpForLevel($level + 1),
                'xp_progress' => $this->xpProgressRatio($level, $xp),
                'streak' => $streak,
                'streak_shields' => $shields,
                'friendship' => $friendship,
            ],
            'tasks' => [],                              // @todo Phase F: aggregate daily quests
            'last7' => $last7,
            'achievements' => $achievements,
        ]);
    }

    public function getSettings(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'push_enabled' => (bool) ($user->push_enabled ?? false),
            'dietary_type' => $user->dietary_type,
            'allergies' => $user->allergies ?? [],
            'dislike_foods' => $user->dislike_foods ?? [],
            'favorite_foods' => $user->favorite_foods ?? [],
            'activity_level' => $user->activity_level,
            'target_weight_kg' => $user->target_weight_kg,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    public function patchSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'push_enabled' => ['nullable', 'boolean'],
            'dietary_type' => ['nullable', 'string', 'max:30'],
            'allergies' => ['nullable', 'array'],
            'dislike_foods' => ['nullable', 'array'],
            'favorite_foods' => ['nullable', 'array'],
            'activity_level' => ['nullable', 'in:sedentary,light,moderate,active'],
            'target_weight_kg' => ['nullable', 'numeric', 'between:30,250'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        foreach ($data as $k => $v) {
            $user->{$k} = $v;
        }
        $user->save();

        return $this->getSettings($request);
    }

    // ────────────────────────────────────────────────────────────────────────
    // Doudou block helpers — small, pure, easy to swap when Phase F lands.
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Map a level number to the Chinese stage name shown in the UI.
     *
     * Buckets mirror the legacy ai-game tiers (新生 / 幼苗 / 成長中 / 堅韌 / 傳奇 / 神話)
     * so existing onboarding copy still makes sense.
     */
    private function levelName(int $level): string
    {
        return match (true) {
            $level >= 50 => '神話',
            $level >= 20 => '傳奇',
            $level >= 10 => '堅韌',
            $level >= 5 => '成長中',
            $level >= 2 => '幼苗',
            default => '新生',
        };
    }

    /**
     * XP needed to reach $level. Linear curve mirrors the legacy ai-game
     * service (50 XP per level). Frontend renders this as the "next level" goal.
     */
    private function xpForLevel(int $level): int
    {
        return max(50, 50 * $level);
    }

    /**
     * Fraction of progress toward the next level, clamped to [0, 1].
     * Frontend multiplies by 100 to set the XP bar width.
     */
    private function xpProgressRatio(int $level, int $xp): float
    {
        $current = $this->xpForLevel($level);          // XP threshold of current level
        $next = $this->xpForLevel($level + 1);
        $span = max(1, $next - $current);
        $into = max(0, $xp - $current);

        return max(0.0, min(1.0, $into / $span));
    }

    /**
     * Pick a friendly mood phrase. Deterministic per user-per-day so the
     * speech bubble doesn't flicker on repeated dashboard polls.
     */
    private function defaultMoodPhrase(User $user): string
    {
        $seed = (int) $user->id + (int) Carbon::today()->format('Ymd');
        $idx = $seed % count(self::MOOD_PHRASES);

        return self::MOOD_PHRASES[$idx];
    }
}
