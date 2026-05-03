<?php

use App\Models\HealthMetric;
use App\Models\Meal;
use App\Models\ProgressSnapshot;
use App\Models\RitualEvent;
use App\Models\User;
use App\Services\HealthMetricsService;
use App\Services\Ritual\StreakRitualService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('checkMealStreak fires KEY_STREAK_MILESTONE at 30 consecutive days', function () {
    $now = CarbonImmutable::parse('2026-05-04', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-meal-30']);
    foreach (range(0, 29) as $i) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $now->subDays($i)->toDateString(),
            'meal_type' => 'lunch', 'food_name' => 'x',
            'recognized_via' => 'manual',
            'calories' => 600, 'carbs_g' => 60, 'protein_g' => 30, 'fat_g' => 15,
        ]);
    }

    app(StreakRitualService::class)->checkMealStreak($user, $now);

    expect(RitualEvent::where('user_id', $user->id)
        ->where('ritual_key', RitualEvent::KEY_STREAK_MILESTONE)
        ->count())->toBe(1);
});

it('checkMealStreak does NOT fire on day 31 (round numbers only)', function () {
    $now = CarbonImmutable::parse('2026-05-04', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-meal-31']);
    foreach (range(0, 30) as $i) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $now->subDays($i)->toDateString(),
            'meal_type' => 'lunch', 'food_name' => 'x',
            'recognized_via' => 'manual',
            'calories' => 600, 'carbs_g' => 60, 'protein_g' => 30, 'fat_g' => 15,
        ]);
    }

    app(StreakRitualService::class)->checkMealStreak($user, $now);

    expect(RitualEvent::where('user_id', $user->id)
        ->where('ritual_key', RitualEvent::KEY_STREAK_MILESTONE)
        ->count())->toBe(0);
});

it('checkWeightLogStreak fires at 30-day weight log streak', function () {
    $now = CarbonImmutable::parse('2026-05-04 10:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-w-30']);
    foreach (range(0, 29) as $i) {
        HealthMetric::create([
            'user_id' => $user->id,
            'type' => HealthMetricsService::TYPE_WEIGHT,
            'value' => 60 - $i * 0.05,
            'unit' => 'kg',
            'recorded_at' => $now->subDays($i),
            'source' => 'test',
        ]);
    }

    app(StreakRitualService::class)->checkWeightLogStreak($user, $now);

    $events = RitualEvent::where('user_id', $user->id)->get();
    expect($events->count())->toBe(1);
    expect($events->first()->payload['streak_kind'])->toBe('weight_log');
    expect($events->first()->payload['streak_count'])->toBe(30);
});

it('checkPhotoStreak fires at 30-day photo streak', function () {
    $now = CarbonImmutable::parse('2026-05-04 10:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-p-30']);
    foreach (range(0, 29) as $i) {
        ProgressSnapshot::create([
            'user_id' => $user->id,
            'taken_at' => $now->subDays($i),
            'weight_g' => 60000 - $i * 50,
        ]);
    }

    app(StreakRitualService::class)->checkPhotoStreak($user, $now);

    expect(RitualEvent::where('user_id', $user->id)
        ->where('ritual_key', RitualEvent::KEY_STREAK_MILESTONE)
        ->count())->toBe(1);
});

it('streak service is idempotent — same milestone doubles do not duplicate', function () {
    $now = CarbonImmutable::parse('2026-05-04', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-idemp-st']);
    foreach (range(0, 29) as $i) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $now->subDays($i)->toDateString(),
            'meal_type' => 'lunch', 'food_name' => 'x',
            'recognized_via' => 'manual',
            'calories' => 600, 'carbs_g' => 60, 'protein_g' => 30, 'fat_g' => 15,
        ]);
    }

    $svc = app(StreakRitualService::class);
    $svc->checkMealStreak($user, $now);
    $svc->checkMealStreak($user, $now);

    expect(RitualEvent::where('user_id', $user->id)->count())->toBe(1);
});

it('streak does not count if today is missing', function () {
    $now = CarbonImmutable::parse('2026-05-04', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-missed']);
    // 30 days but skipping today
    foreach (range(1, 30) as $i) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $now->subDays($i)->toDateString(),
            'meal_type' => 'lunch', 'food_name' => 'x',
            'recognized_via' => 'manual',
            'calories' => 600, 'carbs_g' => 60, 'protein_g' => 30, 'fat_g' => 15,
        ]);
    }

    app(StreakRitualService::class)->checkMealStreak($user, $now);

    expect(RitualEvent::where('user_id', $user->id)->count())->toBe(0);
});

it('MealController::store fires StreakRitualService check', function () {
    $now = CarbonImmutable::parse('2026-05-04', 'Asia/Taipei');
    \Carbon\Carbon::setTestNow($now);
    $user = User::factory()->create(['pandora_user_uuid' => 'u-ctrl-meal']);
    // Pre-seed 29 days of meals (not today yet)
    foreach (range(1, 29) as $i) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $now->subDays($i)->toDateString(),
            'meal_type' => 'lunch', 'food_name' => 'x',
            'recognized_via' => 'manual',
            'calories' => 600, 'carbs_g' => 60, 'protein_g' => 30, 'fat_g' => 15,
        ]);
    }

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => $now->toDateString(),
            'meal_type' => 'lunch', 'food_name' => 'x',
            'calories' => 600, 'carbs_g' => 60, 'protein_g' => 30, 'fat_g' => 15,
        ])
        ->assertCreated();

    expect(RitualEvent::where('user_id', $user->id)
        ->where('ritual_key', RitualEvent::KEY_STREAK_MILESTONE)
        ->count())->toBe(1);

    \Carbon\Carbon::setTestNow();
});
