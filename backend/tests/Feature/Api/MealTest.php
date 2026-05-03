<?php

use App\Models\Meal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('SPEC-correction-v2 PR #4: POST /meals with dishes[] materializes per-dish rows + recalcs totals', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-dish-create']);

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => '2026-05-04',
            'meal_type' => 'lunch',
            'food_name' => '便當',
            'dishes' => [
                [
                    'food_name' => '白飯', 'food_key' => 'rice_white',
                    'portion_multiplier' => 1.0,
                    'kcal' => 320, 'carb_g' => 70, 'protein_g' => 6, 'fat_g' => 0.5,
                    'confidence' => 0.92,
                ],
                [
                    'food_name' => '雞腿', 'food_key' => 'chicken_thigh',
                    'portion_multiplier' => 1.0,
                    'kcal' => 280, 'carb_g' => 0, 'protein_g' => 35, 'fat_g' => 15,
                    'confidence' => 0.92,
                ],
            ],
        ])
        ->assertCreated()
        ->assertJsonCount(2, 'data.dishes')
        ->assertJsonPath('data.macros.calories', 600)
        ->assertJsonPath('data.dishes.0.food_name', '白飯')
        ->assertJsonPath('data.dishes.0.confidence_band', 'high')
        ->assertJsonPath('data.dishes.1.food_name', '雞腿');

    $mealId = $resp->json('data.id');
    expect(\App\Models\MealDish::where('meal_id', $mealId)->count())->toBe(2);
});

it('SPEC-correction-v2 PR #4: GET /meals/{meal} returns dishes[] in resource', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-dish-show']);
    $meal = $user->meals()->create([
        'date' => '2026-05-04', 'meal_type' => 'lunch', 'food_name' => '便當',
        'calories' => 0, 'carbs_g' => 0, 'protein_g' => 0, 'fat_g' => 0,
    ]);
    $meal->dishes()->create([
        'food_name' => '白飯', 'food_key' => 'rice_white',
        'portion_multiplier' => 1.0,
        'kcal' => 320, 'carb_g' => 70, 'protein_g' => 6, 'fat_g' => 0.5,
        'confidence' => 0.92,
        'source' => \App\Models\MealDish::SOURCE_AI_INITIAL,
        'display_order' => 0,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/meals/{$meal->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.dishes')
        ->assertJsonPath('data.dishes.0.food_key', 'rice_white');
});

it('SPEC-correction-v2 PR #4: dishes[] validates portion_multiplier bounds', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-dish-bound']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => '2026-05-04', 'meal_type' => 'lunch',
            'dishes' => [[
                'food_name' => 'x', 'portion_multiplier' => 5.0,
                'kcal' => 100, 'carb_g' => 10, 'protein_g' => 5, 'fat_g' => 1,
            ]],
        ])
        ->assertStatus(422);
});

it('lists current user meals only', function () {
    $user = User::factory()->create();
    Meal::factory()->for($user)->count(2)->create();
    Meal::factory()->count(3)->create(); // other users

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/meals')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters meals by date', function () {
    $user = User::factory()->create();
    Meal::factory()->for($user)->create(['date' => '2026-04-28']);
    Meal::factory()->for($user)->create(['date' => '2026-04-27']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/meals?date=2026-04-28')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('logs a meal via POST', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => '2026-04-28',
            'meal_type' => 'lunch',
            'food_name' => '雞胸便當',
            'calories' => 580,
            'protein_g' => 35,
            'carbs_g' => 70,
            'fat_g' => 18,
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.food_name', '雞胸便當')
        ->assertJsonPath('data.macros.calories', 580);

    expect($user->meals()->count())->toBe(1);
});

it('rejects invalid meal_type', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => '2026-04-28',
            'meal_type' => 'midnight',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('meal_type');
});

it('shows a meal owned by the user', function () {
    $user = User::factory()->create();
    $meal = Meal::factory()->for($user)->create(['food_name' => '早餐三明治']);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/meals/{$meal->id}")
        ->assertOk()
        ->assertJsonPath('data.food_name', '早餐三明治');
});

it('forbids access to another users meal', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $bobMeal = Meal::factory()->for($bob)->create();

    $this->actingAs($alice, 'sanctum')
        ->getJson("/api/meals/{$bobMeal->id}")
        ->assertForbidden();
});

it('deletes own meal', function () {
    $user = User::factory()->create();
    $meal = Meal::factory()->for($user)->create();

    $this->actingAs($user, 'sanctum')
        ->deleteJson("/api/meals/{$meal->id}")
        ->assertNoContent();

    expect(Meal::find($meal->id))->toBeNull();
});
