<?php

use App\Jobs\PublishGamificationEventJob;
use App\Models\Meal;
use App\Models\User;
use App\Services\MealScoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
    Bus::fake();
});

function makeMeal(array $overrides = []): Meal
{
    $user = $overrides['user'] ?? User::factory()->create([
        'pandora_user_uuid' => 'mmmm0000-0000-0000-0000-mmmm00000000',
        'daily_calorie_target' => 1800,
    ]);
    unset($overrides['user']);

    return Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => Carbon::today()->toDateString(),
        'meal_type' => 'lunch',
        'matched_food_ids' => [],
        'calories' => 600,
        'protein_g' => 30,
        'carbs_g' => 60,
        'fat_g' => 20,
        'fiber_g' => 8,
        'sodium_mg' => 600,
        'sugar_g' => 8,
        ...$overrides,
    ]);
}

// ── compute() unit ────────────────────────────────────────────────────

it('returns null when calories missing or zero (no penalty for unscored meals)', function () {
    $user = User::factory()->create(['daily_calorie_target' => 1800]);
    $meal = makeMeal(['user' => $user, 'calories' => 0]);

    expect(app(MealScoreService::class)->compute($meal, $user))->toBeNull();
});

it('scores a near-ideal meal-type-share lunch close to 100', function () {
    // ideal lunch = 1800 * 0.33 = 594. Meal is 600 (within 1.5% of ideal).
    $user = User::factory()->create(['daily_calorie_target' => 1800]);
    $meal = makeMeal(['user' => $user, 'meal_type' => 'lunch', 'calories' => 600]);

    $score = app(MealScoreService::class)->compute($meal, $user);
    // 50 base + 20 calorie + 15 protein density (30g/600=5 g/100kcal → +10) +
    // 8 fibre + 0 sodium + 0 sugar = ~88
    expect($score)->toBeGreaterThanOrEqual(80);
});

it('penalises a wildly over-target meal regardless of macros', function () {
    // ideal lunch 594 kcal; this is 1500 kcal = 2.5x
    $user = User::factory()->create(['daily_calorie_target' => 1800]);
    $meal = makeMeal(['user' => $user, 'meal_type' => 'lunch', 'calories' => 1500, 'protein_g' => 60]);

    $score = app(MealScoreService::class)->compute($meal, $user);
    // 50 + (-15 over-cal) + (4 protein density 4 g/100kcal) + 5 fibre = 44
    expect($score)->toBeLessThan(60);
});

it('does NOT give max score to first-meal-of-day just because budget is empty (regression of ai-game bug)', function () {
    // Original ai-game: breakfast 600 kcal vs remaining budget 1800 → use=33% → +20
    // Our version: ideal breakfast = 1800 * 0.27 = 486; meal 600 → deviation 23% → +12
    $user = User::factory()->create(['daily_calorie_target' => 1800]);
    $breakfast = makeMeal([
        'user' => $user, 'meal_type' => 'breakfast',
        'calories' => 1200,  // way over breakfast share
        'protein_g' => 0, 'fiber_g' => 0, 'sodium_mg' => 0, 'sugar_g' => 0,
    ]);

    $score = app(MealScoreService::class)->compute($breakfast, $user);
    // 50 + (-15 way over) = 35. Old algorithm would have given 50 + 20 = 70.
    expect($score)->toBeLessThan(50);
});

it('penalises high-sodium meals', function () {
    $user = User::factory()->create(['daily_calorie_target' => 1800]);
    $meal = makeMeal(['user' => $user, 'sodium_mg' => 2000]);

    $score = app(MealScoreService::class)->compute($meal, $user);
    $clean = app(MealScoreService::class)->compute(makeMeal(['user' => $user, 'sodium_mg' => 100]), $user);
    expect($score)->toBeLessThan($clean);
});

it('snack with 16g sugar is penalised; main meal with 16g sugar is not', function () {
    $user = User::factory()->create(['daily_calorie_target' => 1800]);
    $snack = makeMeal(['user' => $user, 'meal_type' => 'snack', 'calories' => 180, 'sugar_g' => 16]);
    $lunch = makeMeal(['user' => $user, 'meal_type' => 'lunch', 'sugar_g' => 16]);

    $snackScore = app(MealScoreService::class)->compute($snack, $user);
    $lunchScore = app(MealScoreService::class)->compute($lunch, $user);

    // Sugar penalty hits the snack but not the lunch (threshold 25g for lunch).
    expect($snackScore)->toBeLessThan($lunchScore);
});

it('protein density tiers: 8 g per 100 kcal earns top bonus', function () {
    $user = User::factory()->create(['daily_calorie_target' => 1800]);
    $high = makeMeal(['user' => $user, 'calories' => 600, 'protein_g' => 50]);  // 8.3 g/100kcal
    $low = makeMeal(['user' => $user, 'calories' => 600, 'protein_g' => 10]);   // 1.7 g/100kcal

    expect(app(MealScoreService::class)->compute($high, $user))
        ->toBeGreaterThan(app(MealScoreService::class)->compute($low, $user));
});

it('clamps to 0..100', function () {
    $user = User::factory()->create(['daily_calorie_target' => 1800]);
    $awful = makeMeal([
        'user' => $user,
        'calories' => 5000, 'protein_g' => 0, 'fiber_g' => 0,
        'sodium_mg' => 5000, 'sugar_g' => 80,
    ]);
    $perfect = makeMeal([
        'user' => $user, 'meal_type' => 'lunch', 'calories' => 594,
        'protein_g' => 50, 'fiber_g' => 10, 'sodium_mg' => 100, 'sugar_g' => 5,
    ]);

    expect(app(MealScoreService::class)->compute($awful, $user))->toBeGreaterThanOrEqual(0);
    expect(app(MealScoreService::class)->compute($perfect, $user))->toBeLessThanOrEqual(100);
});

// ── MealController integration ───────────────────────────────────────

it('POST /api/meals computes meal_score server-side and writes it to the row', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'mmmm1111-1111-1111-1111-mmmm11111111',
        'daily_calorie_target' => 1800,
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'lunch',
            'calories' => 600,
            'protein_g' => 30,
            'fiber_g' => 8,
        ])
        ->assertCreated();

    $mealId = (int) $resp->json('data.id');
    $meal = Meal::find($mealId);
    expect($meal->meal_score)->not->toBeNull();
    expect($meal->meal_score)->toBeGreaterThanOrEqual(80);
});

it('POST /api/meals leaves meal_score null when no calories supplied', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'mmmm2222-2222-2222-2222-mmmm22222222',
    ]);
    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'breakfast',
            'food_name' => 'something',
        ])
        ->assertCreated();

    $meal = Meal::find((int) $resp->json('data.id'));
    expect($meal->meal_score)->toBeNull();
});

it('fires dodo.meal_score_80_plus when meal_score ≥ 80', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'mmmm3333-3333-3333-3333-mmmm33333333',
        'daily_calorie_target' => 1800,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'lunch',
            'calories' => 600,
            'protein_g' => 30,
            'fiber_g' => 8,
        ])
        ->assertCreated();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'dodo.meal_score_80_plus'
            && $job->body['metadata']['score'] >= 80;
    });
});

it('does NOT fire dodo.meal_score_80_plus when meal_score < 80', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'mmmm4444-4444-4444-4444-mmmm44444444',
        'daily_calorie_target' => 1800,
    ]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'lunch',
            'calories' => 1500,  // way over → low score
            'protein_g' => 5,
            'sugar_g' => 50,
        ])
        ->assertCreated();

    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'dodo.meal_score_80_plus',
    );
});

it('idempotency_key for meal_score_80_plus uses the meal id', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'mmmm5555-5555-5555-5555-mmmm55555555',
        'daily_calorie_target' => 1800,
    ]);
    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'lunch',
            'calories' => 600,
            'protein_g' => 30,
            'fiber_g' => 8,
        ])
        ->assertCreated();

    $mealId = (int) $resp->json('data.id');
    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) use ($mealId) {
        return $job->body['event_kind'] === 'dodo.meal_score_80_plus'
            && $job->body['idempotency_key'] === "dodo.meal_score_80_plus.{$mealId}";
    });
});
