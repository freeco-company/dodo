<?php

use App\Jobs\PublishAchievementAwardJob;
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

// ── streak_7 / streak_30 ──────────────────────────────────────────────

it('awards dodo.streak_7 when daily-log creation crosses the 7-day threshold', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaa1111-1111-1111-1111-aaaa11111111',
    ]);
    seedStreakDays($user, 6);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertDispatched(PublishAchievementAwardJob::class, function ($job) {
        return $job->body['code'] === 'dodo.streak_7'
            && $job->body['source_app'] === 'dodo';
    });
});

it('does NOT re-award streak_7 once already past threshold', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaa2222-2222-2222-2222-aaaa22222222',
    ]);
    seedStreakDays($user, 7);  // already at LV.7

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertNotDispatched(
        PublishAchievementAwardJob::class,
        fn ($job) => $job->body['code'] === 'dodo.streak_7',
    );
});

it('awards dodo.streak_30 on day-30 threshold and not streak_7', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaa3333-3333-3333-3333-aaaa33333333',
    ]);
    seedStreakDays($user, 29);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertDispatched(
        PublishAchievementAwardJob::class,
        fn ($job) => $job->body['code'] === 'dodo.streak_30',
    );
    Bus::assertNotDispatched(
        PublishAchievementAwardJob::class,
        fn ($job) => $job->body['code'] === 'dodo.streak_7',
    );
});

it('streak_3 / streak_14 do NOT award (XP-only milestones, no badge)', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaa4444-4444-4444-4444-aaaa44444444',
    ]);
    seedStreakDays($user, 2);  // tomorrow → 3-day streak

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertNotDispatched(
        PublishAchievementAwardJob::class,
        fn ($job) => str_starts_with($job->body['code'] ?? '', 'dodo.streak_'),
    );
});

it('idempotency_key for streak achievements is uuid-scoped (one badge ever)', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaa5555-5555-5555-5555-aaaa55555555',
    ]);
    seedStreakDays($user, 6);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertDispatched(PublishAchievementAwardJob::class, function ($job) {
        return $job->body['code'] === 'dodo.streak_7'
            && $job->body['idempotency_key'] === 'dodo.streak_7.aaaa5555-5555-5555-5555-aaaa55555555';
    });
});

// ── first_meal ───────────────────────────────────────────────────────

it('awards dodo.first_meal on the user’s very first meal', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'bbbb1111-1111-1111-1111-bbbb11111111',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'breakfast',
        ])
        ->assertCreated();

    Bus::assertDispatched(PublishAchievementAwardJob::class, function ($job) {
        return $job->body['code'] === 'dodo.first_meal'
            && $job->body['idempotency_key'] === 'dodo.first_meal.bbbb1111-1111-1111-1111-bbbb11111111';
    });
});

it('does NOT re-award first_meal on subsequent meals', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'bbbb2222-2222-2222-2222-bbbb22222222',
    ]);
    Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => Carbon::yesterday()->toDateString(),
        'meal_type' => 'breakfast',
        'matched_food_ids' => [],
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'lunch',
        ])
        ->assertCreated();

    Bus::assertNotDispatched(
        PublishAchievementAwardJob::class,
        fn ($job) => $job->body['code'] === 'dodo.first_meal',
    );
});

// ── env disable ──────────────────────────────────────────────────────

it('publishes nothing when env not configured', function () {
    config()->set('services.pandora_gamification.base_url', '');
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccc1111-1111-1111-1111-cccc11111111',
    ]);
    seedStreakDays($user, 6);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => Carbon::today()->toDateString(),
            'meal_type' => 'breakfast',
        ])
        ->assertCreated();

    Bus::assertNotDispatched(PublishAchievementAwardJob::class);
});
