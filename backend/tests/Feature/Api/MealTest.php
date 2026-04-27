<?php

use App\Models\Meal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
