<?php

use App\Models\Meal;
use App\Models\MealDish;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * SPEC-photo-ai-correction-v2 PR #2 — endpoint contract tests.
 * Service-level coverage in MealCorrectionServiceTest; here we focus on
 * auth, validation, cross-tenant guards, ai-service mocking.
 */
beforeEach(function () {
    config()->set('services.meal_ai_service.base_url', 'https://ai.test');
    config()->set('services.meal_ai_service.shared_secret', 'secret');
    config()->set('services.meal_ai_service.timeout', 5);
});

function makeMealForUser(User $user): Meal
{
    return Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => now()->toDateString(),
        'meal_type' => 'lunch',
        'food_name' => '便當',
        'recognized_via' => 'photo',
        'photo_url' => 'https://x.test/photo.jpg',
        'calories' => 0, 'carbs_g' => 0, 'protein_g' => 0, 'fat_g' => 0,
    ]);
}

function makeDishOnMeal(Meal $meal, array $overrides = []): MealDish
{
    return $meal->dishes()->create(array_merge([
        'food_name' => '白飯',
        'food_key' => 'rice_white',
        'portion_multiplier' => 1.00,
        'kcal' => 320, 'carb_g' => 70, 'protein_g' => 6, 'fat_g' => 0.5,
        'confidence' => 0.92,
        'source' => MealDish::SOURCE_AI_INITIAL,
        'display_order' => 0,
    ], $overrides));
}

it('POST /meals/{meal}/dishes adds a manual dish', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-add']);
    $meal = makeMealForUser($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/meals/{$meal->id}/dishes", [
            'food_name' => '滷蛋',
            'food_key' => 'egg_braised',
            'kcal' => 80, 'carb_g' => 1, 'protein_g' => 6, 'fat_g' => 5,
        ])
        ->assertCreated()
        ->assertJsonPath('data.food_name', '滷蛋')
        ->assertJsonPath('data.source', MealDish::SOURCE_USER_MANUAL);

    expect($meal->fresh()->calories)->toBe(80);
});

it('POST dishes requires authentication', function () {
    $user = User::factory()->create();
    $meal = makeMealForUser($user);

    $this->postJson("/api/meals/{$meal->id}/dishes", [
        'food_name' => 'x', 'kcal' => 100, 'carb_g' => 0, 'protein_g' => 0, 'fat_g' => 0,
    ])->assertUnauthorized();
});

it('POST dishes rejects cross-tenant meal access', function () {
    $owner = User::factory()->create(['pandora_user_uuid' => 'u-owner']);
    $attacker = User::factory()->create(['pandora_user_uuid' => 'u-attacker']);
    $meal = makeMealForUser($owner);

    $this->actingAs($attacker, 'sanctum')
        ->postJson("/api/meals/{$meal->id}/dishes", [
            'food_name' => 'x', 'kcal' => 100, 'carb_g' => 0, 'protein_g' => 0, 'fat_g' => 0,
        ])
        ->assertForbidden();
});

it('PATCH /meals/{meal}/dishes/{dish} updates portion + recalcs', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-patch']);
    $meal = makeMealForUser($user);
    $dish = makeDishOnMeal($meal);
    app(\App\Services\MealCorrectionService::class)->recalcMealTotals($meal);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/meals/{$meal->id}/dishes/{$dish->id}", [
            'portion_multiplier' => 1.5,
            'kcal' => 480, 'carb_g' => 105, 'protein_g' => 9, 'fat_g' => 0.75,
        ])
        ->assertOk()
        ->assertJsonPath('data.portion_multiplier', 1.5);

    expect($meal->fresh()->calories)->toBe(480);
});

it('PATCH dishes returns 403 when dish belongs to another meal', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-mismatch']);
    $mealA = makeMealForUser($user);
    $mealB = makeMealForUser($user);
    $dish = makeDishOnMeal($mealA);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/meals/{$mealB->id}/dishes/{$dish->id}", ['portion_multiplier' => 1.5])
        ->assertForbidden();
});

it('DELETE /meals/{meal}/dishes/{dish} removes + logs', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-del']);
    $meal = makeMealForUser($user);
    $dish = makeDishOnMeal($meal);

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/meals/{$meal->id}/dishes/{$dish->id}")
        ->assertOk();

    expect(MealDish::find($dish->id))->toBeNull();
});

it('POST /meals/{meal}/dishes/{dish}/refine calls ai-service', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-refine']);
    $meal = makeMealForUser($user);
    $dish = makeDishOnMeal($meal);

    Http::fake([
        'ai.test/v1/vision/refine' => Http::response([
            'dishes' => [
                [
                    'food_name' => '糙米', 'food_key' => 'rice_brown',
                    'portion_multiplier' => 1.0, 'kcal' => 270,
                    'carb_g' => 58, 'protein_g' => 8, 'fat_g' => 1.6,
                    'confidence' => 0.88,
                ],
            ],
        ], 200),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/meals/{$meal->id}/dishes/{$dish->id}/refine", [
            'new_food_key' => 'rice_brown',
        ])
        ->assertOk()
        ->assertJsonPath('data.food_key', 'rice_brown')
        ->assertJsonPath('data.source', MealDish::SOURCE_AI_REFINED);
});

it('GET /meals/{meal}/dishes/{dish}/candidates returns candidates_json', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-cand']);
    $meal = makeMealForUser($user);
    $dish = makeDishOnMeal($meal, [
        'candidates_json' => [
            ['food_key' => 'rice_brown', 'food_name' => '糙米', 'confidence' => 0.78],
            ['food_key' => 'rice_grain', 'food_name' => '五穀飯', 'confidence' => 0.65],
        ],
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/meals/{$meal->id}/dishes/{$dish->id}/candidates")
        ->assertOk()
        ->assertJsonCount(2, 'candidates');
});

it('PATCH validates portion_multiplier within 0.25-3.0 bound', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-bound']);
    $meal = makeMealForUser($user);
    $dish = makeDishOnMeal($meal);

    $this->actingAs($user, 'sanctum')
        ->patchJson("/api/meals/{$meal->id}/dishes/{$dish->id}", ['portion_multiplier' => 5.0])
        ->assertStatus(422);
});

it('MealDishResource returns confidence_band high/medium/low based on threshold', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-band']);
    $meal = makeMealForUser($user);

    $high = makeDishOnMeal($meal, ['confidence' => 0.92, 'display_order' => 0]);
    $med = makeDishOnMeal($meal, ['confidence' => 0.75, 'display_order' => 1]);
    $low = makeDishOnMeal($meal, ['confidence' => 0.55, 'display_order' => 2]);

    $resourceHigh = (new \App\Http\Resources\MealDishResource($high))->toArray(request());
    $resourceMed = (new \App\Http\Resources\MealDishResource($med))->toArray(request());
    $resourceLow = (new \App\Http\Resources\MealDishResource($low))->toArray(request());

    expect($resourceHigh['confidence_band'])->toBe('high');
    expect($resourceMed['confidence_band'])->toBe('medium');
    expect($resourceLow['confidence_band'])->toBe('low');
});
