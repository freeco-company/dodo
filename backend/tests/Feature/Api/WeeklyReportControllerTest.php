<?php

use App\Models\FastingSession;
use App\Models\HealthMetric;
use App\Models\Meal;
use App\Models\User;
use App\Models\WeeklyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
    Bus::fake();
});

/**
 * SPEC-weekly-ai-report Phase 1 — `/api/reports/weekly/{current,history,by-date,shared}`.
 */

it('current returns an empty-state narrative when user has zero data', function () {
    $user = User::factory()->create();

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/reports/weekly/current')
        ->assertOk();

    expect($resp->json('meals.count'))->toBe(0);
    expect($resp->json('fasting.sessions'))->toBe(0);
    expect($resp->json('health.total_steps'))->toBe(0);
    expect($resp->json('narrative.headline'))->toContain('還沒開始');
    expect($resp->json('tier'))->toBe('free');
    expect($resp->json('features.image_card'))->toBeFalse();
});

it('current aggregates meals + fasting + health into the payload', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaa3333-3333-3333-3333-333333333333',
    ]);
    $today = now('Asia/Taipei');

    Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => $today->toDateString(),
        'meal_type' => 'lunch',
        'food_name' => '雞腿便當',
        'calories' => 720,
    ]);
    FastingSession::create([
        'user_id' => $user->id,
        'mode' => '16:8',
        'target_duration_minutes' => 960,
        'started_at' => $today->copy()->subMinutes(970),
        'ended_at' => $today,
        'completed' => true,
        'source_app' => 'dodo',
    ]);
    HealthMetric::create([
        'user_id' => $user->id, 'type' => 'steps', 'value' => 7500, 'unit' => 'count',
        'recorded_at' => $today, 'source' => 'healthkit',
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/reports/weekly/current')
        ->assertOk();

    expect($resp->json('meals.count'))->toBe(1);
    expect($resp->json('meals.total_kcal'))->toBe(720);
    expect($resp->json('meals.top_foods.0.name'))->toBe('雞腿便當');
    expect($resp->json('fasting.completed'))->toBe(1);
    expect($resp->json('health.total_steps'))->toBe(7500);
    expect($resp->json('id'))->toBeInt();
});

it('paid users get the unlimited / image_card feature flags', function () {
    $user = User::factory()->create(['membership_tier' => 'fp_lifetime']);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/reports/weekly/current')
        ->assertOk();

    expect($resp->json('tier'))->toBe('paid');
    expect($resp->json('features.image_card'))->toBeTrue();
    expect($resp->json('features.history_unlimited'))->toBeTrue();
    expect($resp->json('features.sleep_visible'))->toBeTrue();
    expect($resp->json('features.history_capped_weeks'))->toBeNull();
});

it('history caps free users to 4 weeks', function () {
    $user = User::factory()->create();
    for ($i = 0; $i < 6; $i++) {
        WeeklyReport::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'week_start' => now()->subWeeks($i + 1)->startOfWeek(0)->toDateString(),
            'week_end' => now()->subWeeks($i + 1)->startOfWeek(0)->addDays(6)->toDateString(),
            'avg_score' => 75 + $i,
        ]);
    }

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/reports/weekly/history?weeks=12')
        ->assertOk();

    expect($resp->json('data'))->toHaveCount(4);
});

it('history returns up to N weeks for paid users', function () {
    $user = User::factory()->create(['membership_tier' => 'fp_lifetime']);
    for ($i = 0; $i < 6; $i++) {
        WeeklyReport::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'week_start' => now()->subWeeks($i + 1)->startOfWeek(0)->toDateString(),
            'week_end' => now()->subWeeks($i + 1)->startOfWeek(0)->addDays(6)->toDateString(),
            'avg_score' => 80,
        ]);
    }

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/reports/weekly/history?weeks=12')
        ->assertOk();

    expect($resp->json('data'))->toHaveCount(6);
});

it('shared bumps the counter and only on own reports', function () {
    $user = User::factory()->create();
    $report = WeeklyReport::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'week_start' => now()->startOfWeek(0)->toDateString(),
        'week_end' => now()->startOfWeek(0)->addDays(6)->toDateString(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/reports/weekly/{$report->id}/shared")
        ->assertOk()
        ->assertJsonPath('shared_count', 1);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/reports/weekly/{$report->id}/shared")
        ->assertOk()
        ->assertJsonPath('shared_count', 2);

    // Other user — 404
    $other = User::factory()->create();
    $this->actingAs($other, 'sanctum')
        ->postJson("/api/reports/weekly/{$report->id}/shared")
        ->assertStatus(404);
});

it('by-date validates YYYY-MM-DD', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/reports/weekly/by-date/garbage')
        ->assertStatus(422);
});

it('all 4 endpoints reject unauthenticated requests', function () {
    $this->getJson('/api/reports/weekly/current')->assertStatus(401);
    $this->getJson('/api/reports/weekly/history')->assertStatus(401);
    $this->getJson('/api/reports/weekly/by-date/2026-04-26')->assertStatus(401);
    $this->postJson('/api/reports/weekly/1/shared')->assertStatus(401);
});

it('reports:generate-weekly artisan command runs without error on empty system', function () {
    $this->artisan('reports:generate-weekly')->assertSuccessful();
});
