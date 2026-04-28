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
