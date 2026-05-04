<?php

use App\Jobs\PublishGamificationEventJob;
use App\Models\DailyLog;
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

// `seedStreakDays($user, $count)` lives in tests/Pest.php so it doesn't
// disturb `$this` binding inside `it(...)` closures here.

it('fires meal.streak_3 when today brings the streak to exactly 3', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '11111111-aaaa-bbbb-cccc-111111111111',
    ]);
    seedStreakDays($user, 2);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'meal.streak_3'
            && $job->body['source_app'] === 'meal'
            && $job->body['metadata']['streak_days'] === 3;
    });
});

it('fires meal.streak_7 when today is the 7th consecutive day', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '22222222-aaaa-bbbb-cccc-222222222222',
    ]);
    seedStreakDays($user, 6);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'meal.streak_7';
    });
});

it('does NOT re-fire streak_7 on day 8 (already crossed yesterday)', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '33333333-aaaa-bbbb-cccc-333333333333',
    ]);
    seedStreakDays($user, 7);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.streak_7',
    );
});

it('fires streak_14 but not streak_7 when streak crosses 14 (yesterday already past 7)', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '44444444-aaaa-bbbb-cccc-444444444444',
    ]);
    seedStreakDays($user, 13);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.streak_7',
    );
    Bus::assertDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.streak_14',
    );
});

it('idempotency_key carries (uuid, threshold, today) so daily retries cannot double-credit', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '55555555-aaaa-bbbb-cccc-555555555555',
    ]);
    seedStreakDays($user, 6);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return str_starts_with($job->body['idempotency_key'], 'meal.streak_7.55555555-')
            && str_ends_with($job->body['idempotency_key'], Carbon::today()->toDateString());
    });
});

it('does not fire when no streak (single isolated day)', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '66666666-aaaa-bbbb-cccc-666666666666',
    ]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    // Threshold streak fires (meal.streak_3 / 7 / 14 / 30) must NOT dispatch
    // when streak<3. The milestone_unlocked event (SPEC-streak-milestone-rewards)
    // DOES dispatch at streak=1 — it's a separate concern, exclude here.
    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => in_array(
            $job->body['event_kind'] ?? '',
            ['meal.streak_3', 'meal.streak_7', 'meal.streak_14', 'meal.streak_30'],
            true,
        ),
    );
});

it('publisher noops when env not configured (publisher-disabled)', function () {
    config()->set('services.pandora_gamification.base_url', '');
    $user = User::factory()->create([
        'pandora_user_uuid' => '77777777-aaaa-bbbb-cccc-777777777777',
    ]);
    seedStreakDays($user, 6);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 100])
        ->assertOk();

    Bus::assertNotDispatched(PublishGamificationEventJob::class);
});
