<?php

use App\Jobs\PublishGamificationEventJob;
use App\Models\HealthMetric;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
    Bus::fake();
});

/**
 * SPEC-healthkit-integration Phase 1 — POST /api/health/sync,
 * GET /api/health/today, GET /api/health/history.
 */

it('syncs a batch of free metrics and dedups on resync', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaa2222-2222-2222-2222-222222222222',
    ]);

    $payload = [
        'metrics' => [
            ['type' => 'steps', 'value' => 5234, 'unit' => 'count', 'recorded_at' => '2026-05-03T00:00:00+08:00'],
            ['type' => 'weight', 'value' => 53.2, 'unit' => 'kg', 'recorded_at' => '2026-05-03T07:30:00+08:00'],
        ],
    ];

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/health/sync', $payload)
        ->assertOk()
        ->assertJsonPath('accepted', 2)
        ->assertJsonPath('rejected', 0);

    expect(HealthMetric::where('user_id', $user->id)->count())->toBe(2);

    // Re-sync same batch with corrected values → upsert, not duplicate
    $payload['metrics'][0]['value'] = 5400;
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/health/sync', $payload)
        ->assertOk();

    expect(HealthMetric::where('user_id', $user->id)->count())->toBe(2);
    $steps = HealthMetric::where('user_id', $user->id)->where('type', 'steps')->first();
    expect((int) $steps->value)->toBe(5400);
});

it('rejects paid types (sleep) for free users', function () {
    $user = User::factory()->create();

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/health/sync', [
            'metrics' => [
                ['type' => 'steps', 'value' => 1000, 'unit' => 'count', 'recorded_at' => '2026-05-03T00:00:00+08:00'],
                ['type' => 'sleep_minutes', 'value' => 432, 'unit' => 'min', 'recorded_at' => '2026-05-03T07:00:00+08:00'],
            ],
        ])
        ->assertOk();

    expect($resp->json('accepted'))->toBe(1);
    expect($resp->json('rejected'))->toBe(1);
    expect($resp->json('reasons.paid_type_for_free_user'))->toBe(1);
});

it('accepts paid types for fp_lifetime users', function () {
    $user = User::factory()->create(['membership_tier' => 'fp_lifetime']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/health/sync', [
            'metrics' => [
                ['type' => 'sleep_minutes', 'value' => 432, 'unit' => 'min', 'recorded_at' => '2026-05-03T07:00:00+08:00'],
                ['type' => 'heart_rate', 'value' => 62, 'unit' => 'bpm', 'recorded_at' => '2026-05-03T07:00:00+08:00'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('accepted', 2);
});

it('rejects unknown types', function () {
    $user = User::factory()->create();

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/health/sync', [
            'metrics' => [
                ['type' => 'blood_glucose', 'value' => 5.4, 'unit' => 'mmol/L', 'recorded_at' => '2026-05-03T08:00:00+08:00'],
            ],
        ])
        ->assertOk();

    expect($resp->json('rejected'))->toBe(1);
    expect($resp->json('reasons.unknown_type'))->toBe(1);
});

it('publishes meal.steps_goal_achieved when steps >= 6000', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'bbbb2222-2222-2222-2222-222222222222',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/health/sync', [
            'metrics' => [['type' => 'steps', 'value' => 7500, 'unit' => 'count', 'recorded_at' => '2026-05-03T23:00:00+08:00']],
        ])
        ->assertOk();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'meal.steps_goal_achieved'
            && $job->body['metadata']['steps'] === 7500;
    });
});

it('does NOT publish steps_goal when below 6000', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccc2222-2222-2222-2222-222222222222',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/health/sync', [
            'metrics' => [['type' => 'steps', 'value' => 3000, 'unit' => 'count', 'recorded_at' => '2026-05-03T23:00:00+08:00']],
        ])
        ->assertOk();

    Bus::assertNotDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'meal.steps_goal_achieved';
    });
});

it('today snapshot returns null fields when no data', function () {
    $user = User::factory()->create();

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/health/today')
        ->assertOk();

    expect($resp->json('steps'))->toBeNull();
    expect($resp->json('weight_kg'))->toBeNull();
    expect($resp->json('has_any_data'))->toBeFalse();
    expect($resp->json('sleep_locked'))->toBeTrue();
    expect($resp->json('steps_goal'))->toBe(6000);
});

it('today snapshot reflects most recent weight from any past day', function () {
    $user = User::factory()->create();
    HealthMetric::create([
        'user_id' => $user->id,
        'type' => 'weight',
        'value' => 54.0,
        'unit' => 'kg',
        'recorded_at' => now()->subDays(3),
        'source' => 'healthkit',
    ]);
    HealthMetric::create([
        'user_id' => $user->id,
        'type' => 'weight',
        'value' => 53.5,
        'unit' => 'kg',
        'recorded_at' => now()->subDays(1),
        'source' => 'healthkit',
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/health/today')
        ->assertOk();

    expect($resp->json('weight_kg'))->toBe(53.5);
    expect($resp->json('has_any_data'))->toBeTrue();
});

it('history caps free users to 7 days', function () {
    $user = User::factory()->create();
    HealthMetric::create([
        'user_id' => $user->id, 'type' => 'steps', 'value' => 5000, 'unit' => 'count',
        'recorded_at' => now()->subDays(20), 'source' => 'healthkit',
    ]);
    HealthMetric::create([
        'user_id' => $user->id, 'type' => 'steps', 'value' => 6500, 'unit' => 'count',
        'recorded_at' => now()->subDays(2), 'source' => 'healthkit',
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/health/history?type=steps&days=30')
        ->assertOk();

    expect($resp->json('data'))->toHaveCount(1);
    expect($resp->json('history_capped_days'))->toBe(7);
});

it('all 3 endpoints reject unauthenticated requests', function () {
    $this->postJson('/api/health/sync', ['metrics' => []])->assertStatus(401);
    $this->getJson('/api/health/today')->assertStatus(401);
    $this->getJson('/api/health/history?type=steps')->assertStatus(401);
});

it('history validates type whitelist', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/health/history?type=unknown')
        ->assertStatus(422);
});

it('prune nulls raw_payload on rows older than the window', function () {
    $user = User::factory()->create();
    $old = HealthMetric::create([
        'user_id' => $user->id, 'type' => 'steps', 'value' => 1000, 'unit' => 'count',
        'recorded_at' => now()->subDays(120), 'source' => 'healthkit',
        'raw_payload' => ['hk' => 'old'],
    ]);
    $recent = HealthMetric::create([
        'user_id' => $user->id, 'type' => 'steps', 'value' => 1000, 'unit' => 'count',
        'recorded_at' => now()->subDays(10), 'source' => 'healthkit',
        'raw_payload' => ['hk' => 'recent'],
    ]);

    $this->artisan('health:prune', ['--days' => 90])->assertSuccessful();

    expect($old->fresh()->raw_payload)->toBeNull();
    expect($recent->fresh()->raw_payload)->toBe(['hk' => 'recent']);
});
