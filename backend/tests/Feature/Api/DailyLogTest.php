<?php

use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists current user daily logs', function () {
    $user = User::factory()->create();
    DailyLog::factory()->for($user)->count(3)->create();
    DailyLog::factory()->count(2)->create(); // other users — must not leak

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/daily-logs')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'date', 'scores', 'macros']]]);
});

it('filters daily logs by date range', function () {
    $user = User::factory()->create();
    DailyLog::factory()->for($user)->create(['date' => '2026-04-01']);
    DailyLog::factory()->for($user)->create(['date' => '2026-04-15']);
    DailyLog::factory()->for($user)->create(['date' => '2026-04-28']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/daily-logs?from=2026-04-10&to=2026-04-20')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('shows a single daily log by date', function () {
    $user = User::factory()->create();
    DailyLog::factory()->for($user)->create(['date' => '2026-04-28', 'total_score' => 88]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/daily-logs/2026-04-28')
        ->assertOk()
        ->assertJsonPath('data.scores.total', 88);
});

it('returns 404 when daily log does not exist', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/daily-logs/2026-04-28')
        ->assertNotFound();
});

it('upserts daily log via POST', function () {
    $user = User::factory()->create();

    $first = $this->actingAs($user, 'sanctum')
        ->postJson('/api/daily-logs', [
            'date' => '2026-04-28',
            'water_ml' => 1500,
        ])->assertCreated()->json('data');

    $second = $this->actingAs($user, 'sanctum')
        ->postJson('/api/daily-logs', [
            'date' => '2026-04-28',
            'water_ml' => 2500,
            'exercise_minutes' => 30,
        ])->assertOk()->json('data');

    expect($first['id'])->toBe($second['id'])
        ->and($second['water_ml'])->toBe(2500)
        ->and($second['exercise_minutes'])->toBe(30);

    expect($user->dailyLogs()->count())->toBe(1);
});

it('refuses unauthenticated access', function () {
    $this->getJson('/api/daily-logs')->assertUnauthorized();
});
