<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use App\Services\DailyLogAggregator;
use App\Services\FoodDiscoveryService;
use App\Services\Gamification\AchievementPublisher;
use App\Services\Gamification\GamificationPublisher;
use App\Services\MealScoreService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MealController extends Controller
{
    public function __construct(
        private readonly GamificationPublisher $gamification,
        private readonly AchievementPublisher $achievements,
        private readonly FoodDiscoveryService $foodDiscovery,
        private readonly DailyLogAggregator $dailyLog,
        private readonly MealScoreService $mealScore,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user()->meals()
            ->orderByDesc('created_at')
            ->limit($request->integer('limit', 30));

        if ($date = $request->input('date')) {
            $query->whereDate('date', $date);
        }

        return MealResource::collection($query->get());
    }

    public function store(Request $request): MealResource
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'meal_type' => ['required', 'in:breakfast,lunch,dinner,snack'],
            'photo_url' => ['nullable', 'url', 'max:1024'],
            'food_name' => ['nullable', 'string', 'max:120'],
            'food_components' => ['nullable', 'array'],
            'serving_weight_g' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'calories' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'protein_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'carbs_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'fat_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'fiber_g' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sodium_mg' => ['nullable', 'numeric', 'min:0', 'max:20000'],
            'sugar_g' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'matched_food_ids' => ['nullable', 'array', 'max:20'],
            'matched_food_ids.*' => ['integer', 'min:1'],
        ]);

        $matchedFoodIds = $data['matched_food_ids'] ?? [];
        unset($data['matched_food_ids']);

        $user = $request->user();
        /** @var Meal $meal */
        $meal = $user->meals()->create($data + [
            'matched_food_ids' => $matchedFoodIds,
        ]);

        // ADR-009 §3.1 — compute per-meal score; null when nutrition data
        // missing (manual freeform entry). Server is authoritative — request
        // payload doesn't override.
        $score = $this->mealScore->compute($meal, $user);
        if ($score !== null) {
            $meal->meal_score = $score;
            $meal->save();
            $uuidForMealScore = is_string($user->pandora_user_uuid) ? $user->pandora_user_uuid : '';
            if ($uuidForMealScore !== '' && $score >= 80) {
                $this->gamification->publish(
                    $uuidForMealScore,
                    'meal.meal_score_80_plus',
                    "meal.meal_score_80_plus.{$meal->id}",
                    ['meal_id' => $meal->id, 'score' => $score],
                );
            }
        }

        // ADR-009 §3.1 — record food discoveries (Pokémon-style) which in turn
        // fires `meal.new_food_discovered` per new foodid + `meal.foodie_10`
        // achievement when the user reaches 10 distinct foods.
        $this->foodDiscovery->recordFromMeal($user, $meal);

        // Re-aggregate daily totals from meals + recompute daily score; fires
        // `meal.daily_score_80_plus` if the new score crosses 80. (catalog §3.1)
        $this->dailyLog->recompute($user, $meal->date->toDateString());

        // ADR-009 §3 / catalog §3.1 — fire gamification events for this meal.
        $uuid = is_string($user->pandora_user_uuid) ? $user->pandora_user_uuid : '';
        if ($uuid !== '') {
            // meal.meal_logged — fires every meal; daily cap (30 XP / 6 meals)
            // and diminishing returns (4th meal onwards = 2 XP) live server-side
            // in catalog.EVENT_CATALOG so caller doesn't need to think about it.
            $this->gamification->publish(
                $uuid,
                'meal.meal_logged',
                "meal.meal_logged.{$meal->id}",
                ['meal_id' => $meal->id, 'meal_type' => $meal->meal_type],
            );

            // meal.first_meal_of_day — only when this is the first meal logged
            // for the meal's date. Catalog daily_cap_xp=5 enforces the once-a-day
            // cap on the server even if our detection is imperfect.
            $mealsToday = $user->meals()
                ->whereDate('date', $meal->date)
                ->where('id', '!=', $meal->id)
                ->count();
            if ($mealsToday === 0) {
                $this->gamification->publish(
                    $uuid,
                    'meal.first_meal_of_day',
                    "meal.first_meal_of_day.{$uuid}.".$meal->date->toDateString(),
                    ['meal_id' => $meal->id],
                );
            }

            // ADR-009 §5 — `meal.first_meal` achievement on the user's
            // first-ever meal. Cheap exact count when the user has only one
            // meal so far; we already know `$mealsToday` above so a 0 there
            // narrows it. py-service is idempotent on (uuid, code) so a stray
            // double-publish is harmless.
            if ($mealsToday === 0) {
                $totalMeals = $user->meals()
                    ->where('id', '!=', $meal->id)
                    ->count();
                if ($totalMeals === 0) {
                    $this->achievements->publish(
                        $uuid,
                        'meal.first_meal',
                        "meal.first_meal.{$uuid}",
                        ['meal_id' => $meal->id, 'meal_type' => $meal->meal_type],
                    );
                }
            }
        }

        // SPEC-cross-metric-insight-v1 PR #6 — realtime evaluate. Fires
        // milestone insights (streak_30 etc.) same-day rather than waiting
        // for the daily cron. Engine is idempotent (cooldown + week-bucket
        // idempotency_key), so multiple meal posts in a day are safe.
        \App\Jobs\EvaluateInsightsForUserJob::dispatch($user->id)->afterCommit()->onQueue('default');

        return new MealResource($meal);
    }

    public function show(Request $request, Meal $meal): MealResource
    {
        if ($meal->user_id !== $request->user()->id) {
            throw new AuthorizationException('not your meal');
        }

        return new MealResource($meal);
    }

    public function destroy(Request $request, Meal $meal): \Illuminate\Http\JsonResponse
    {
        if ($meal->user_id !== $request->user()->id) {
            throw new AuthorizationException('not your meal');
        }

        $meal->delete();

        return response()->json(null, 204);
    }

    /**
     * PUT /api/meals/{meal}/correct — user-supplied correction to AI's
     * meal-recognition result (rename, adjust serving, etc).
     *
     * Marks the meal `user_corrected = true` and persists the supplied
     * fields. Down-weighting AI confidence happens here as a deterministic
     * signal; the Python service consumes this back-channel later.
     */
    public function correct(Request $request, Meal $meal): MealResource
    {
        if ($meal->user_id !== $request->user()->id) {
            throw new AuthorizationException('not your meal');
        }

        $data = $request->validate([
            'food_name' => ['nullable', 'string', 'max:120'],
            'serving_weight_g' => ['nullable', 'numeric', 'min:0', 'max:5000'],
            'calories' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'protein_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'carbs_g' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'fat_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'meal_type' => ['nullable', 'in:breakfast,lunch,dinner,snack'],
        ]);

        if (empty($data)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'body' => ['at least one corrected field required'],
            ]);
        }

        $original = $meal->only(array_keys($data));
        foreach ($data as $k => $v) {
            $meal->{$k} = $v;
        }
        $meal->user_corrected = true;
        // correction_data is cast to 'array' on the Meal model — but PHPStan
        // sees the raw column type (string|null), so coerce defensively.
        /** @var array<int,array<string,mixed>> $existing */
        $existing = (array) ($meal->correction_data ?? []);
        $existing[] = [
            'corrected_at' => now()->toIso8601String(),
            'before' => $original,
            'after' => $data,
        ];
        $meal->correction_data = $existing;
        $meal->save();

        return new MealResource($meal->fresh());
    }
}
