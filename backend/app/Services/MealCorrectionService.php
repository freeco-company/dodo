<?php

namespace App\Services;

use App\Models\FoodCorrection;
use App\Models\Meal;
use App\Models\MealDish;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SPEC-photo-ai-correction-v2 — apply per-dish corrections + recalc meal totals
 * + log every change for the calibration feedback loop.
 *
 * Wired by:
 *   - PATCH /api/meals/{meal}/dishes/{dish}     applyDishCorrection
 *   - POST  /api/meals/{meal}/dishes            addManualDish
 *   - DELETE /api/meals/{meal}/dishes/{dish}    removeDish
 *   - POST  /api/meals/{meal}/dishes/{dish}/refine  refineDishViaAi
 *
 * recalcMealTotals() syncs meals.{calories, protein_g, carbs_g, fat_g} from dishes,
 * keeping the legacy aggregate columns truthful for downstream services
 * (DailyLogAggregator, GrowthService, WeeklyReportService).
 */
class MealCorrectionService
{
    public function __construct(
        private readonly AiServiceClient $ai,
    ) {}

    /**
     * Apply a partial update to a dish (food swap, portion change, or both).
     * Logs the appropriate FoodCorrection rows + recalcs meal totals.
     *
     * @param  array{
     *     food_name?: string,
     *     food_key?: string,
     *     portion_multiplier?: float,
     *     portion_unit?: string,
     *     kcal?: int,
     *     carb_g?: float,
     *     protein_g?: float,
     *     fat_g?: float,
     * }  $payload
     */
    public function applyDishCorrection(MealDish $dish, User $user, array $payload): MealDish
    {
        return DB::transaction(function () use ($dish, $user, $payload) {
            $original = $dish->only([
                'food_key', 'portion_multiplier', 'kcal', 'carb_g', 'protein_g', 'fat_g', 'confidence',
            ]);

            $foodChanged = array_key_exists('food_key', $payload)
                && $payload['food_key'] !== $original['food_key'];
            $portionChanged = array_key_exists('portion_multiplier', $payload)
                && (float) $payload['portion_multiplier'] !== (float) $original['portion_multiplier'];

            $dish->fill($this->onlyAllowedFields($payload));
            if ($foodChanged) {
                $dish->source = MealDish::SOURCE_USER_SWAPPED;
                $dish->confidence = null;
            } elseif ($portionChanged) {
                $dish->source = $dish->source === MealDish::SOURCE_AI_INITIAL
                    ? MealDish::SOURCE_USER_SWAPPED
                    : $dish->source;
            }
            $dish->save();

            if ($foodChanged) {
                FoodCorrection::create([
                    'user_id' => $user->id,
                    'meal_dish_id' => $dish->id,
                    'correction_type' => FoodCorrection::TYPE_FOOD_SWAP,
                    'original_food_key' => $original['food_key'],
                    'corrected_food_key' => $dish->food_key,
                    'original_confidence' => $original['confidence'],
                    'context_json' => ['meal_id' => $dish->meal_id],
                ]);
            }
            if ($portionChanged) {
                FoodCorrection::create([
                    'user_id' => $user->id,
                    'meal_dish_id' => $dish->id,
                    'correction_type' => FoodCorrection::TYPE_PORTION_CHANGE,
                    'original_food_key' => $dish->food_key,
                    'corrected_food_key' => $dish->food_key,
                    'original_portion' => $original['portion_multiplier'],
                    'corrected_portion' => $dish->portion_multiplier,
                    'original_confidence' => $original['confidence'],
                    'context_json' => ['meal_id' => $dish->meal_id],
                ]);
            }

            $this->recalcMealTotals($dish->meal);

            return $dish->refresh();
        });
    }

    /**
     * User manually adds a dish the AI missed (or post-hoc additions).
     *
     * @param  array{food_name: string, food_key?: ?string, portion_multiplier?: float,
     *     portion_unit?: ?string, kcal: int, carb_g: float, protein_g: float, fat_g: float}  $payload
     */
    public function addManualDish(Meal $meal, User $user, array $payload): MealDish
    {
        /** @var MealDish $dish */
        $dish = DB::transaction(function () use ($meal, $user, $payload) {
            /** @var MealDish $dish */
            $dish = $meal->dishes()->create([
                'food_name' => $payload['food_name'],
                'food_key' => $payload['food_key'] ?? null,
                'portion_multiplier' => $payload['portion_multiplier'] ?? 1.00,
                'portion_unit' => $payload['portion_unit'] ?? null,
                'kcal' => $payload['kcal'],
                'carb_g' => $payload['carb_g'],
                'protein_g' => $payload['protein_g'],
                'fat_g' => $payload['fat_g'],
                'confidence' => null,
                'source' => MealDish::SOURCE_USER_MANUAL,
                'display_order' => ($meal->dishes()->max('display_order') ?? -1) + 1,
            ]);

            FoodCorrection::create([
                'user_id' => $user->id,
                'meal_dish_id' => $dish->id,
                'correction_type' => FoodCorrection::TYPE_ADD_MISSING,
                'corrected_food_key' => $dish->food_key,
                'corrected_portion' => $dish->portion_multiplier,
                'context_json' => ['meal_id' => $meal->id],
            ]);

            $this->recalcMealTotals($meal);

            return $dish;
        });

        return $dish;
    }

    public function removeDish(MealDish $dish, User $user): void
    {
        DB::transaction(function () use ($dish, $user) {
            $meal = $dish->meal;

            FoodCorrection::create([
                'user_id' => $user->id,
                'meal_dish_id' => null,
                'correction_type' => FoodCorrection::TYPE_REMOVE,
                'original_food_key' => $dish->food_key,
                'original_portion' => $dish->portion_multiplier,
                'original_confidence' => $dish->confidence,
                'context_json' => ['meal_id' => $dish->meal_id, 'food_name' => $dish->food_name],
            ]);

            $dish->delete();
            $this->recalcMealTotals($meal);
        });
    }

    /**
     * Trigger AI re-inference on a dish using the original image + user hint.
     * If ai-service is unavailable, returns the dish unchanged + logs a soft warning.
     *
     * @param  array{new_food_key?: ?string, new_food_name?: ?string, new_portion?: ?float}  $userHint
     */
    public function refineDishViaAi(MealDish $dish, User $user, array $userHint): MealDish
    {
        $meal = $dish->meal;

        try {
            $response = $this->ai->refineMealDish($user, [
                'image_url' => $meal->photo_url,
                'original_dishes' => $this->serializeDishesForAi($meal),
                'user_hint' => array_merge(['dish_index' => $this->dishIndex($meal, $dish)], $userHint),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $dish;
        }

        return DB::transaction(function () use ($dish, $user, $response, $meal) {
            $refined = $response['dishes'][$this->dishIndex($meal, $dish)] ?? null;
            if ($refined === null) {
                return $dish;
            }

            $original = $dish->only(['food_key', 'portion_multiplier', 'confidence']);

            $dish->fill([
                'food_name' => $refined['food_name'] ?? $dish->food_name,
                'food_key' => $refined['food_key'] ?? $dish->food_key,
                'portion_multiplier' => $refined['portion_multiplier'] ?? $dish->portion_multiplier,
                'kcal' => (int) ($refined['kcal'] ?? $dish->kcal),
                'carb_g' => (float) ($refined['carb_g'] ?? $dish->carb_g),
                'protein_g' => (float) ($refined['protein_g'] ?? $dish->protein_g),
                'fat_g' => (float) ($refined['fat_g'] ?? $dish->fat_g),
                'confidence' => $refined['confidence'] ?? null,
                'source' => MealDish::SOURCE_AI_REFINED,
                'candidates_json' => $refined['candidates'] ?? $dish->candidates_json,
            ]);
            $dish->save();

            FoodCorrection::create([
                'user_id' => $user->id,
                'meal_dish_id' => $dish->id,
                'correction_type' => FoodCorrection::TYPE_AI_REFINE,
                'original_food_key' => $original['food_key'],
                'corrected_food_key' => $dish->food_key,
                'original_portion' => $original['portion_multiplier'],
                'corrected_portion' => $dish->portion_multiplier,
                'original_confidence' => $original['confidence'],
                'context_json' => ['meal_id' => $dish->meal_id, 'hint' => $response['user_hint'] ?? null],
            ]);

            $this->recalcMealTotals($meal);

            return $dish->refresh();
        });
    }

    /**
     * Per-user food calibration hint sent to ai-service on next recognize/refine
     * to bias the prompt: 「user 通常 portion 比 AI 估的少 15%」.
     *
     * Returns null when sample size < 3 (avoids over-fitting on noise).
     *
     * @return ?array{portion_bias: float, sample_count: int}
     */
    public function userCalibrationFor(User $user, string $foodKey): ?array
    {
        $rows = FoodCorrection::query()
            ->where('user_id', $user->id)
            ->where('corrected_food_key', $foodKey)
            ->where('correction_type', FoodCorrection::TYPE_PORTION_CHANGE)
            ->whereNotNull('original_portion')
            ->whereNotNull('corrected_portion')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        if ($rows->count() < 3) {
            return null;
        }

        $biases = $rows->map(fn ($r) => ($r->corrected_portion - $r->original_portion) / max($r->original_portion, 0.01));

        return [
            'portion_bias' => round($biases->avg(), 3),
            'sample_count' => $rows->count(),
        ];
    }

    public function recalcMealTotals(?Meal $meal): void
    {
        if ($meal === null) {
            return;
        }
        /** @var \Illuminate\Database\Eloquent\Collection<int,MealDish> $dishes */
        $dishes = $meal->dishes()->get();
        $meal->forceFill([
            'calories' => (int) round((float) $dishes->sum('kcal')),
            'carbs_g' => round((float) $dishes->sum('carb_g'), 2),
            'protein_g' => round((float) $dishes->sum('protein_g'), 2),
            'fat_g' => round((float) $dishes->sum('fat_g'), 2),
            'user_corrected' => true,
        ])->save();
    }

    /** @return array<int,array<string,mixed>> */
    private function serializeDishesForAi(?Meal $meal): array
    {
        if ($meal === null) {
            return [];
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int,MealDish> $dishes */
        $dishes = $meal->dishes()->get();

        return $dishes->map(fn (MealDish $d) => [
            'food_name' => $d->food_name,
            'food_key' => $d->food_key,
            'portion_multiplier' => (float) $d->portion_multiplier,
            'kcal' => $d->kcal,
            'carb_g' => (float) $d->carb_g,
            'protein_g' => (float) $d->protein_g,
            'fat_g' => (float) $d->fat_g,
            'confidence' => $d->confidence,
        ])->all();
    }

    private function dishIndex(?Meal $meal, MealDish $dish): int
    {
        if ($meal === null) {
            return 0;
        }

        return $meal->dishes()->orderBy('display_order')->pluck('id')->search($dish->id) ?: 0;
    }

    /** @return array<string,mixed> */
    private function onlyAllowedFields(array $payload): array
    {
        return array_intersect_key($payload, array_flip([
            'food_name', 'food_key', 'portion_multiplier', 'portion_unit',
            'kcal', 'carb_g', 'protein_g', 'fat_g',
        ]));
    }
}
