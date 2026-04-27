<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MealResource;
use App\Models\Meal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MealController extends Controller
{
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
        ]);

        $meal = $request->user()->meals()->create($data + [
            'matched_food_ids' => [],
        ]);

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
}
