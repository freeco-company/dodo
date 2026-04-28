<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\DailyLog;
use App\Models\Meal;
use App\Services\GameXp;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/me/* — current-user-scoped endpoints.
 *
 * Resolves the user from the sanctum bearer token (no uid in path).
 * Replaces legacy ai-game routes /user/:uid/dashboard and /user/:uid/settings.
 *
 * Dashboard logic mirrors ai-game/src/services/dashboard.ts at a slimmer
 * level — just the fields the frontend home tab actually consumes today.
 *
 * @todo Phase F: port full mood / tasks / level-stage block once mascot
 *       generation is wired through Python AI.
 */
class MeController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = Carbon::today()->toDateString();

        $daily = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('date', $today)
            ->first();

        $todayMeals = Meal::where('pandora_user_uuid', $user->pandora_user_uuid)
            ->where('date', $today)
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

        $xp = (int) ($user->xp ?? 0);
        $levelDef = GameXp::levelDefForXp($xp);
        $next = GameXp::xpForNextLevel($xp);
        $calTarget = (int) ($user->daily_calorie_target ?? 1800);
        $score = $daily ? (int) $daily->total_score : 0;
        $streak = (int) ($user->current_streak ?? 0);
        $mood = $this->deriveMood($score, $streak);

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
                'daily_calorie_target' => $calTarget,
                'daily_protein_target_g' => $user->daily_protein_target_g,
                'subscription_tier' => $user->membership_tier,
            ],
            'doudou' => [
                'level' => $levelDef['level'],
                'level_name' => $levelDef['name'],
                'xp' => $xp,
                'xp_next_level' => $next ? $next['xpNeeded'] : 0,
                'xp_progress' => $next ? $next['progress'] : 1.0,
                'streak' => $streak,
                'longest_streak' => (int) ($user->longest_streak ?? 0),
                'streak_shields' => (int) ($user->streak_shields ?? 0),
                'friendship' => (int) ($user->friendship ?? 0),
                'species' => $user->avatar_species,
                'mood' => $mood['key'],
                'mood_phrase' => $mood['phrase'],
            ],
            'today' => [
                'date' => $today,
                'score' => $score,
                'meals_logged' => $daily ? (int) $daily->meals_logged : 0,
                'calories' => $caloriesToday,
                'calories_target' => $calTarget,
                'remaining_calories' => max(0, $calTarget - $caloriesToday),
                'protein_g' => round($proteinToday, 1),
                'protein_target_g' => (float) ($user->daily_protein_target_g ?? 80),
                'meals' => $todayMeals,
            ],
            'progress' => [
                'level' => $levelDef['level'],
                'xp' => $xp,
                'current_streak' => $streak,
                'longest_streak' => (int) ($user->longest_streak ?? 0),
                'friendship' => (int) ($user->friendship ?? 0),
            ],
            'tasks' => [],
            'last7' => $last7,
            'achievements' => $achievements,
        ]);
    }

    /** @return array{key:string, phrase:string} */
    private function deriveMood(int $score, int $streak): array
    {
        if ($score >= 80) {
            return ['key' => 'happy', 'phrase' => '今天好棒！繼續保持～'];
        }
        if ($score >= 60) {
            return ['key' => 'neutral', 'phrase' => '不錯哦，再多一點點！'];
        }
        if ($streak >= 3) {
            return ['key' => 'cheerful', 'phrase' => '連續打卡 '.$streak.' 天，妳超棒！'];
        }
        return ['key' => 'sleepy', 'phrase' => '一起記錄今天吃了什麼吧？'];
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
            'daily_water_goal_ml' => (int) ($user->daily_water_goal_ml ?? 3000),
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
            'daily_water_goal_ml' => ['nullable', 'integer', 'between:500,8000'],
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        foreach ($data as $k => $v) {
            $user->{$k} = $v;
        }
        $user->save();

        return $this->getSettings($request);
    }
}
