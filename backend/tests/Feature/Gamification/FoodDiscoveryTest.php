<?php

use App\Jobs\PublishAchievementAwardJob;
use App\Jobs\PublishGamificationEventJob;
use App\Models\Food;
use App\Models\FoodDiscovery;
use App\Models\Meal;
use App\Models\User;
use App\Services\FoodDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
    Bus::fake();
});

// ── service unit ───────────────────────────────────────────────────────

it('inserts a food_discovery row on first encounter', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'ffff1111-1111-1111-1111-ffff11111111',
    ]);
    $food = Food::factory()->create();
    $meal = Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => Carbon::today()->toDateString(),
        'meal_type' => 'breakfast',
        'matched_food_ids' => [$food->id],
        'meal_score' => 70,
    ]);

    app(FoodDiscoveryService::class)->recordFromMeal($user, $meal);

    $row = FoodDiscovery::where('pandora_user_uuid', $user->pandora_user_uuid)->first();
    expect($row)->not->toBeNull();
    expect($row->food_id)->toBe($food->id);
    expect($row->times_eaten)->toBe(1);
    expect((int) $row->best_score)->toBe(70);
    expect($row->is_shiny)->toBeFalse();
});

it('updates times_eaten + best_score on repeat encounter (no extra row)', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'ffff2222-2222-2222-2222-ffff22222222',
    ]);
    $food = Food::factory()->create();

    foreach ([60, 80, 75] as $score) {
        $meal = Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'lunch',
            'matched_food_ids' => [$food->id],
            'meal_score' => $score,
        ]);
        app(FoodDiscoveryService::class)->recordFromMeal($user, $meal);
    }

    $rows = FoodDiscovery::where('pandora_user_uuid', $user->pandora_user_uuid)->get();
    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]->times_eaten)->toBe(3);
    expect((int) $rows[0]->best_score)->toBe(80);
});

it('marks is_shiny when meal_score crosses SHINY_THRESHOLD (90)', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'ffff3333-3333-3333-3333-ffff33333333',
    ]);
    $food = Food::factory()->create();
    $meal = Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => Carbon::today()->toDateString(),
        'meal_type' => 'breakfast',
        'matched_food_ids' => [$food->id],
        'meal_score' => 95,
    ]);
    app(FoodDiscoveryService::class)->recordFromMeal($user, $meal);

    $row = FoodDiscovery::where('pandora_user_uuid', $user->pandora_user_uuid)->first();
    expect($row->is_shiny)->toBeTrue();
});

// ── new_food_discovered event ─────────────────────────────────────────

it('fires dodo.new_food_discovered on first encounter only', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'ffff4444-4444-4444-4444-ffff44444444',
    ]);
    $food = Food::factory()->create();

    $meal1 = Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => Carbon::today()->toDateString(),
        'meal_type' => 'breakfast',
        'matched_food_ids' => [$food->id],
        'meal_score' => 50,
    ]);
    app(FoodDiscoveryService::class)->recordFromMeal($user, $meal1);

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) use ($food) {
        return $job->body['event_kind'] === 'dodo.new_food_discovered'
            && $job->body['metadata']['food_id'] === $food->id;
    });

    Bus::fake();  // reset

    // Same food again — must NOT fire
    $meal2 = Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => Carbon::today()->toDateString(),
        'meal_type' => 'lunch',
        'matched_food_ids' => [$food->id],
        'meal_score' => 60,
    ]);
    app(FoodDiscoveryService::class)->recordFromMeal($user, $meal2);

    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'dodo.new_food_discovered',
    );
});

// ── foodie_10 achievement ─────────────────────────────────────────────

it('fires dodo.foodie_10 only when the user reaches 10 distinct foods', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'ffff5555-5555-5555-5555-ffff55555555',
    ]);
    $foods = Food::factory()->count(10)->create();

    foreach ($foods as $i => $food) {
        $meal = Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'snack',
            'matched_food_ids' => [$food->id],
            'meal_score' => 60,
        ]);
        app(FoodDiscoveryService::class)->recordFromMeal($user, $meal);
    }

    // Should fire exactly when totalreached 10
    Bus::assertDispatched(
        PublishAchievementAwardJob::class,
        fn ($job) => $job->body['code'] === 'dodo.foodie_10',
    );
});

it('does NOT fire foodie_10 before reaching 10 foods', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'ffff6666-6666-6666-6666-ffff66666666',
    ]);
    $foods = Food::factory()->count(9)->create();

    foreach ($foods as $food) {
        $meal = Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'snack',
            'matched_food_ids' => [$food->id],
            'meal_score' => 60,
        ]);
        app(FoodDiscoveryService::class)->recordFromMeal($user, $meal);
    }

    Bus::assertNotDispatched(
        PublishAchievementAwardJob::class,
        fn ($job) => $job->body['code'] === 'dodo.foodie_10',
    );
});

// ── multi-food meal ───────────────────────────────────────────────────

it('records multiple matched_food_ids in one meal as separate discoveries', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'ffff7777-7777-7777-7777-ffff77777777',
    ]);
    $foods = Food::factory()->count(3)->create();

    $meal = Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => Carbon::today()->toDateString(),
        'meal_type' => 'dinner',
        'matched_food_ids' => $foods->pluck('id')->all(),
        'meal_score' => 70,
    ]);
    app(FoodDiscoveryService::class)->recordFromMeal($user, $meal);

    $rows = FoodDiscovery::where('pandora_user_uuid', $user->pandora_user_uuid)->get();
    expect($rows)->toHaveCount(3);
});

// ── MealController integration ───────────────────────────────────────

it('POST /api/meals with matched_food_ids triggers food discovery flow', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'ffff8888-8888-8888-8888-ffff88888888',
    ]);
    $food = Food::factory()->create();

    // Send nutrition that scores >=90 server-side (MealScoreService).
    // Breakfast ideal=486 kcal at 1800 daily target. 500 kcal + 40g protein
    // (8 g/100kcal density) + 8g fibre + low sodium/sugar → ~93 score.
    $user->update(['daily_calorie_target' => 1800]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'breakfast',
            'matched_food_ids' => [$food->id],
            'calories' => 500,
            'protein_g' => 40,
            'fiber_g' => 8,
            'sodium_mg' => 200,
            'sugar_g' => 5,
        ])
        ->assertCreated();

    $row = FoodDiscovery::where('pandora_user_uuid', $user->pandora_user_uuid)->first();
    expect($row)->not->toBeNull();
    expect($row->is_shiny)->toBeTrue();

    Bus::assertDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'dodo.new_food_discovered',
    );
});

it('POST /api/meals without matched_food_ids does not touch food_discoveries', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'ffff9999-9999-9999-9999-ffff99999999',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'breakfast',
        ])
        ->assertCreated();

    expect(FoodDiscovery::count())->toBe(0);
    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'dodo.new_food_discovered',
    );
});
