<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\DailyLog;
use App\Models\Meal;
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
                'daily_calorie_target' => $user->daily_calorie_target,
                'daily_protein_target_g' => $user->daily_protein_target_g,
                'subscription_tier' => $user->membership_tier,
            ],
            'today' => [
                'date' => $today,
                'score' => $daily ? (int) $daily->total_score : 0,
                'meals_logged' => $daily ? (int) $daily->meals_logged : 0,
                'calories' => $caloriesToday,
                'calories_target' => (int) ($user->daily_calorie_target ?? 1800),
                'protein_g' => round($proteinToday, 1),
                'protein_target_g' => (float) ($user->daily_protein_target_g ?? 80),
                'meals' => $todayMeals,
            ],
            'progress' => [
                'level' => (int) ($user->level ?? 1),
                'xp' => (int) ($user->xp ?? 0),
                'current_streak' => (int) ($user->current_streak ?? 0),
                'longest_streak' => (int) ($user->longest_streak ?? 0),
                'friendship' => (int) ($user->friendship ?? 0),
            ],
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
}
