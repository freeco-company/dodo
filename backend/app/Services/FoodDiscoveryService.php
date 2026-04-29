<?php

namespace App\Services;

use App\Models\FoodDiscovery;
use App\Models\Meal;
use App\Models\User;
use App\Services\Gamification\AchievementPublisher;
use App\Services\Gamification\GamificationPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Pokemon-style food discovery — translated from
 * `ai-game/src/services/pokedex.ts`.
 *
 * For each food id matched in a meal, upserts a `food_discoveries` row:
 *   - First time → INSERT, fire `meal.new_food_discovered` (catalog §3.1).
 *     If the meal also crossed SHINY_THRESHOLD, mark is_shiny=1 immediately.
 *   - Repeat → UPDATE times_eaten + maybe upgrade best_score / unlock shiny.
 *
 * Achievement triggers:
 *   - `meal.foodie_10` when the user reaches 10 distinct discoveries (catalog §5.2).
 *
 * Idempotency: db-level UNIQUE(user_id, food_id) guards the row; gamification
 * events use stable idempotency_keys so retries don't double-credit.
 */
class FoodDiscoveryService
{
    public const SHINY_THRESHOLD = 90;

    public const FOODIE_ACHIEVEMENT_THRESHOLD = 10;

    public function __construct(
        private readonly GamificationPublisher $gamification,
        private readonly AchievementPublisher $achievements,
    ) {}

    /**
     * Record discoveries for every matched food id in this meal. Pure write —
     * no return value because callers don't use it (UX shows the badge from
     * the resulting `food_discoveries` row when the user opens pokedex).
     */
    public function recordFromMeal(User $user, Meal $meal): void
    {
        $uuid = is_string($user->pandora_user_uuid) ? $user->pandora_user_uuid : '';
        if ($uuid === '') {
            return;
        }
        $matched = $meal->matched_food_ids;
        if (! is_array($matched) || $matched === []) {
            return;
        }
        $mealScore = $meal->meal_score !== null ? (int) $meal->meal_score : null;

        $newDiscoveries = 0;
        foreach ($matched as $foodId) {
            // Skip non-int / non-string food ids defensively
            if (! is_int($foodId) && ! is_string($foodId)) {
                continue;
            }
            $foodId = (int) $foodId;
            if ($foodId <= 0) {
                continue;
            }

            $isNew = $this->upsertOne($user, $uuid, $foodId, $mealScore);
            if ($isNew) {
                $newDiscoveries++;
                $this->gamification->publish(
                    $uuid,
                    'meal.new_food_discovered',
                    "meal.new_food_discovered.{$uuid}.{$foodId}",
                    ['food_id' => $foodId, 'meal_id' => $meal->id],
                );
            }
        }

        // Foodie achievement check — only when at least one new discovery was
        // added, otherwise the count is unchanged.
        if ($newDiscoveries > 0) {
            $total = FoodDiscovery::where('pandora_user_uuid', $uuid)->count();
            if ($total >= self::FOODIE_ACHIEVEMENT_THRESHOLD) {
                // py-service is idempotent on (uuid, code) — once unlocked,
                // subsequent publishes are no-ops.
                $this->achievements->publish(
                    $uuid,
                    'meal.foodie_10',
                    "meal.foodie_10.{$uuid}",
                    ['discoveries' => $total],
                );
            }
        }
    }

    /**
     * Upsert one (user, food) pair. Returns true iff a new row was inserted.
     */
    private function upsertOne(User $user, string $uuid, int $foodId, ?int $mealScore): bool
    {
        return DB::transaction(function () use ($user, $uuid, $foodId, $mealScore): bool {
            $existing = FoodDiscovery::where('pandora_user_uuid', $uuid)
                ->where('food_id', $foodId)
                ->lockForUpdate()
                ->first();
            $becameShiny = $mealScore !== null && $mealScore >= self::SHINY_THRESHOLD;

            if ($existing !== null) {
                $existing->times_eaten = (int) $existing->times_eaten + 1;
                if ($mealScore !== null) {
                    if ($existing->best_score === null || $mealScore > (int) $existing->best_score) {
                        $existing->best_score = $mealScore;
                    }
                }
                if (! $existing->is_shiny && $becameShiny) {
                    $existing->is_shiny = true;
                }
                $existing->save();

                return false;
            }

            FoodDiscovery::create([
                'user_id' => $user->id,
                'pandora_user_uuid' => $uuid,
                'food_id' => $foodId,
                'first_seen_at' => now(),
                'times_eaten' => 1,
                'best_score' => $mealScore,
                'is_shiny' => $becameShiny,
            ]);

            return true;
        });
    }
}
