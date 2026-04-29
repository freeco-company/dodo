<?php

use App\Jobs\PublishGamificationEventJob;
use App\Models\DailyLog;
use App\Models\Meal;
use App\Models\User;
use App\Services\DailyLogAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
    Bus::fake();
});

// ── DailyLogAggregator unit ───────────────────────────────────────────

it('aggregates meal totals into daily_log columns', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'dddd1111-1111-1111-1111-dddd11111111',
        'daily_calorie_target' => 1800,
        'daily_protein_target_g' => 80,
    ]);
    $today = Carbon::today()->toDateString();

    foreach ([[600, 30], [500, 25], [400, 20]] as [$cal, $p]) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $today,
            'meal_type' => 'snack',
            'matched_food_ids' => [],
            'calories' => $cal,
            'protein_g' => $p,
        ]);
    }

    $result = app(DailyLogAggregator::class)->recompute($user, $today);

    $log = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)->first();
    expect($log->meals_logged)->toBe(3);
    expect($log->total_calories)->toBe(1500);
    expect((float) $log->total_protein_g)->toBe(75.0);
    expect($result['total_score'])->toBeGreaterThan(0);
});

it('fires meal.daily_score_80_plus when score crosses 80', function () {
    // Calorie 1800 + protein 80 + 3 meals + 30 min exercise + 2000ml water → 100
    $user = User::factory()->create([
        'pandora_user_uuid' => 'dddd2222-2222-2222-2222-dddd22222222',
        'daily_calorie_target' => 1800,
        'daily_protein_target_g' => 80,
    ]);
    $today = Carbon::today()->toDateString();

    DailyLog::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => $today,
        'water_ml' => 2000,
        'exercise_minutes' => 30,
    ]);
    foreach ([[600, 30], [600, 30], [600, 30]] as [$cal, $p]) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $today,
            'meal_type' => 'breakfast',
            'matched_food_ids' => [],
            'calories' => $cal,
            'protein_g' => $p,
        ]);
    }

    app(DailyLogAggregator::class)->recompute($user, $today);

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) use ($today) {
        return $job->body['event_kind'] === 'meal.daily_score_80_plus'
            && str_ends_with($job->body['idempotency_key'], $today)
            && $job->body['metadata']['score'] >= 80;
    });
});

it('does NOT fire daily_score_80_plus when score < 80', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'dddd3333-3333-3333-3333-dddd33333333',
        'daily_calorie_target' => 1800,
        'daily_protein_target_g' => 80,
    ]);
    $today = Carbon::today()->toDateString();
    Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => $today,
        'meal_type' => 'snack',
        'matched_food_ids' => [],
        'calories' => 200,
        'protein_g' => 5,
    ]);

    app(DailyLogAggregator::class)->recompute($user, $today);

    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.daily_score_80_plus',
    );
});

it('idempotency_key per (uuid, date) lets server dedup multiple recomputes', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'dddd4444-4444-4444-4444-dddd44444444',
        'daily_calorie_target' => 1800,
        'daily_protein_target_g' => 80,
    ]);
    $today = Carbon::today()->toDateString();
    DailyLog::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => $today,
        'water_ml' => 2000,
        'exercise_minutes' => 30,
    ]);
    foreach (range(1, 3) as $_) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $today,
            'meal_type' => 'lunch',
            'matched_food_ids' => [],
            'calories' => 600,
            'protein_g' => 30,
        ]);
    }

    app(DailyLogAggregator::class)->recompute($user, $today);
    app(DailyLogAggregator::class)->recompute($user, $today);
    app(DailyLogAggregator::class)->recompute($user, $today);

    $jobs = collect(Bus::dispatched(PublishGamificationEventJob::class))
        ->filter(fn ($j) => ($j->body['event_kind'] ?? '') === 'meal.daily_score_80_plus');
    foreach ($jobs as $job) {
        expect($job->body['idempotency_key'])->toBe(
            "meal.daily_score_80_plus.{$user->pandora_user_uuid}.{$today}",
        );
    }
    expect($jobs->count())->toBeGreaterThan(0);
});

// ── MealController integration ───────────────────────────────────────

it('POST /api/meals updates daily_log totals via the aggregator', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'dddd5555-5555-5555-5555-dddd55555555',
        'daily_calorie_target' => 1800,
        'daily_protein_target_g' => 80,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'lunch',
            'calories' => 700,
            'protein_g' => 35,
        ])
        ->assertCreated();

    $log = DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)->first();
    expect($log)->not->toBeNull();
    expect($log->meals_logged)->toBe(1);
    expect($log->total_calories)->toBe(700);
    expect((float) $log->total_protein_g)->toBe(35.0);
});

// ── CheckinService integration ───────────────────────────────────────

it('water/exercise crossing 80 fires daily_score_80_plus once', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'dddd6666-6666-6666-6666-dddd66666666',
        'daily_calorie_target' => 1800,
        'daily_protein_target_g' => 80,
    ]);
    $today = Carbon::today()->toDateString();

    // Pre-seed meals so calorie+protein+consistency components fill ~65, then
    // water + exercise push over 80
    foreach ([[600, 30], [600, 30], [600, 30]] as [$cal, $p]) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $today,
            'meal_type' => 'breakfast',
            'matched_food_ids' => [],
            'calories' => $cal,
            'protein_g' => $p,
        ]);
    }
    // Aggregate once so the meal totals are in daily_log
    app(DailyLogAggregator::class)->recompute($user, $today);
    Bus::fake();  // reset

    // Now water + exercise via CheckinService
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 2000])
        ->assertOk();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/exercise', ['minutes' => 30])
        ->assertOk();

    Bus::assertDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.daily_score_80_plus',
    );
});
