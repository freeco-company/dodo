<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MealDishResource;
use App\Models\Meal;
use App\Models\MealDish;
use App\Services\MealCorrectionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SPEC-photo-ai-correction-v2 PR #2 — per-dish correction endpoints.
 *
 *  POST   /api/meals/{meal}/dishes                  addManualDish
 *  PATCH  /api/meals/{meal}/dishes/{dish}           applyDishCorrection
 *  DELETE /api/meals/{meal}/dishes/{dish}           removeDish
 *  POST   /api/meals/{meal}/dishes/{dish}/refine    refineDishViaAi
 *  GET    /api/meals/{meal}/dishes/{dish}/candidates 取既有 candidates_json
 *
 * Authorization: meal must belong to the authenticated user (cross-tenant guard).
 */
class MealDishController extends Controller
{
    public function __construct(
        private readonly MealCorrectionService $corrections,
    ) {}

    public function store(Request $request, Meal $meal): MealDishResource
    {
        $this->guardOwnership($request, $meal);

        $data = $request->validate([
            'food_name' => ['required', 'string', 'max:120'],
            'food_key' => ['nullable', 'string', 'max:64'],
            'portion_multiplier' => ['nullable', 'numeric', 'between:0.25,3.0'],
            'portion_unit' => ['nullable', 'string', 'max:16'],
            'kcal' => ['required', 'integer', 'min:0', 'max:5000'],
            'carb_g' => ['required', 'numeric', 'min:0', 'max:500'],
            'protein_g' => ['required', 'numeric', 'min:0', 'max:500'],
            'fat_g' => ['required', 'numeric', 'min:0', 'max:500'],
        ]);

        $dish = $this->corrections->addManualDish($meal, $request->user(), $data);

        return new MealDishResource($dish);
    }

    public function update(Request $request, Meal $meal, MealDish $dish): MealDishResource
    {
        $this->guardOwnership($request, $meal);
        $this->guardDishMeal($dish, $meal);

        $data = $request->validate([
            'food_name' => ['nullable', 'string', 'max:120'],
            'food_key' => ['nullable', 'string', 'max:64'],
            'portion_multiplier' => ['nullable', 'numeric', 'between:0.25,3.0'],
            'portion_unit' => ['nullable', 'string', 'max:16'],
            'kcal' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'carb_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'protein_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'fat_g' => ['nullable', 'numeric', 'min:0', 'max:500'],
        ]);

        $updated = $this->corrections->applyDishCorrection($dish, $request->user(), $data);

        return new MealDishResource($updated);
    }

    public function destroy(Request $request, Meal $meal, MealDish $dish): JsonResponse
    {
        $this->guardOwnership($request, $meal);
        $this->guardDishMeal($dish, $meal);

        $this->corrections->removeDish($dish, $request->user());

        return response()->json(['ok' => true]);
    }

    public function refine(Request $request, Meal $meal, MealDish $dish): MealDishResource
    {
        $this->guardOwnership($request, $meal);
        $this->guardDishMeal($dish, $meal);

        $hint = $request->validate([
            'new_food_key' => ['nullable', 'string', 'max:64'],
            'new_food_name' => ['nullable', 'string', 'max:120'],
            'new_portion' => ['nullable', 'numeric', 'between:0.25,3.0'],
        ]);

        $refined = $this->corrections->refineDishViaAi($dish, $request->user(), $hint);

        return new MealDishResource($refined);
    }

    public function candidates(Request $request, Meal $meal, MealDish $dish): JsonResponse
    {
        $this->guardOwnership($request, $meal);
        $this->guardDishMeal($dish, $meal);

        return response()->json([
            'candidates' => $dish->candidates_json ?? [],
        ]);
    }

    private function guardOwnership(Request $request, Meal $meal): void
    {
        if ($meal->user_id !== $request->user()->id) {
            throw new AuthorizationException('cross-tenant access');
        }
    }

    private function guardDishMeal(MealDish $dish, Meal $meal): void
    {
        if ($dish->meal_id !== $meal->id) {
            throw new AuthorizationException('dish does not belong to meal');
        }
    }
}
