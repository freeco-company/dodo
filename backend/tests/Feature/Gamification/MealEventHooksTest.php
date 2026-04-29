<?php

use App\Jobs\PublishGamificationEventJob;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
    Bus::fake();
});

it('fires meal.meal_logged on POST /api/meals', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaaaaaa-1111-1111-1111-111111111111',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'lunch',
            'food_name' => 'salmon bowl',
            'calories' => 600,
        ])
        ->assertCreated();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'meal.meal_logged'
            && $job->body['source_app'] === 'meal'
            && str_starts_with($job->body['idempotency_key'], 'meal.meal_logged.')
            && $job->body['metadata']['meal_type'] === 'lunch';
    });
});

it('fires meal.first_meal_of_day for the first meal of the date and not for subsequent ones', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'bbbbbbbb-2222-2222-2222-222222222222',
    ]);
    $today = Carbon::today()->toDateString();

    // First meal of the day
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => $today,
            'meal_type' => 'breakfast',
        ])
        ->assertCreated();

    Bus::assertDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.first_meal_of_day',
    );

    Bus::fake();  // reset captured jobs

    // Second meal — should NOT fire first_meal_of_day again
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => $today,
            'meal_type' => 'lunch',
        ])
        ->assertCreated();

    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.first_meal_of_day',
    );
    Bus::assertDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.meal_logged',
    );
});

it('first_meal_of_day fires again on a different date', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccccccc-3333-3333-3333-333333333333',
    ]);
    $yesterday = Carbon::today()->subDay()->toDateString();
    $today = Carbon::today()->toDateString();

    Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => $yesterday,
        'meal_type' => 'breakfast',
        'matched_food_ids' => [],
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => $today,
            'meal_type' => 'breakfast',
        ])
        ->assertCreated();

    Bus::assertDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.first_meal_of_day'
            && str_ends_with($job->body['idempotency_key'], $today),
    );
});

it('idempotency_key for meal_logged uses the meal id so retries cannot double-credit', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'dddddddd-4444-4444-4444-444444444444',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'snack',
        ])
        ->assertCreated();

    $mealId = $response->json('data.id');
    expect($mealId)->toBeInt();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) use ($mealId) {
        return $job->body['event_kind'] === 'meal.meal_logged'
            && $job->body['idempotency_key'] === "meal.meal_logged.{$mealId}";
    });
});

it('publisher noops when env not configured', function () {
    config()->set('services.pandora_gamification.base_url', '');
    $user = User::factory()->create([
        'pandora_user_uuid' => 'eeeeeeee-5555-5555-5555-555555555555',
    ]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'breakfast',
        ])
        ->assertCreated();

    Bus::assertNotDispatched(PublishGamificationEventJob::class);
});
