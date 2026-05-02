<?php

use App\Models\FastingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Contract tests for POST /api/fasting/{start,end} + GET /api/fasting/{current,history}.
 *
 * SPEC-fasting-timer Phase 1 — covers:
 *   - happy path start (free 16:8 mode)
 *   - paid-only mode (18:6) returns 402 for free user, 201 for paid
 *   - second start while one is active → 422
 *   - end with no active → 404
 *   - end after target → completed=true
 *   - history capped to 7 days for free
 *   - all 4 endpoints require auth
 */

it('starts a free 16:8 session for an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/start', ['mode' => '16:8'])
        ->assertCreated()
        ->assertJsonPath('session.mode', '16:8')
        ->assertJsonPath('session.target_duration_minutes', 960)
        ->assertJsonPath('session.completed', false)
        ->assertJsonPath('snapshot.phase', 'digesting');

    expect(FastingSession::where('user_id', $user->id)->count())->toBe(1);
});

it('rejects a second concurrent session with 422', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/start', ['mode' => '16:8'])
        ->assertCreated();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/start', ['mode' => '14:10'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'FASTING_ALREADY_ACTIVE');
});

it('blocks free users from advanced modes (18:6) with 402', function () {
    $user = User::factory()->create(); // free tier

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/start', ['mode' => '18:6'])
        ->assertStatus(402)
        ->assertJsonPath('error_code', 'FASTING_MODE_LOCKED')
        ->assertJsonPath('paywall.reason', 'fasting_advanced_mode');
});

it('allows paid (fp_lifetime) users to use 18:6', function () {
    $user = User::factory()->create(['membership_tier' => 'fp_lifetime']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/start', ['mode' => '18:6'])
        ->assertCreated()
        ->assertJsonPath('session.mode', '18:6')
        ->assertJsonPath('session.target_duration_minutes', 1080);
});

it('end returns 404 when no active session', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/end')
        ->assertStatus(404)
        ->assertJsonPath('error_code', 'FASTING_NO_ACTIVE');
});

it('ends an active session and marks completed=true when target met', function () {
    $user = User::factory()->create();
    $startedAt = now()->subMinutes(970); // 16h10m → above 16:8 target

    FastingSession::create([
        'user_id' => $user->id,
        'mode' => '16:8',
        'target_duration_minutes' => 960,
        'started_at' => $startedAt,
        'source_app' => 'dodo',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/end')
        ->assertOk()
        ->assertJsonPath('session.completed', true);
});

it('ends an active session as completed=false when below target', function () {
    $user = User::factory()->create();
    FastingSession::create([
        'user_id' => $user->id,
        'mode' => '16:8',
        'target_duration_minutes' => 960,
        'started_at' => now()->subMinutes(120),
        'source_app' => 'dodo',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/end')
        ->assertOk()
        ->assertJsonPath('session.completed', false);
});

it('current returns null snapshot when no active session', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/fasting/current')
        ->assertOk()
        ->assertJsonPath('snapshot', null);
});

it('current returns derived elapsed_minutes and progress', function () {
    $user = User::factory()->create();
    FastingSession::create([
        'user_id' => $user->id,
        'mode' => '16:8',
        'target_duration_minutes' => 960,
        'started_at' => now()->subMinutes(480),
        'source_app' => 'dodo',
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/fasting/current')
        ->assertOk();

    expect($resp->json('snapshot.elapsed_minutes'))->toBeGreaterThanOrEqual(479)->toBeLessThanOrEqual(481);
    expect($resp->json('snapshot.progress'))->toBeGreaterThan(0.49)->toBeLessThan(0.51);
    expect($resp->json('snapshot.phase'))->toBe('glycogen_switch'); // 8-12h band
});

it('history caps free users to last 7 days', function () {
    $user = User::factory()->create();

    // Old completed session (10 days ago) — should be hidden for free
    FastingSession::create([
        'user_id' => $user->id,
        'mode' => '16:8',
        'target_duration_minutes' => 960,
        'started_at' => now()->subDays(10)->subMinutes(60),
        'ended_at' => now()->subDays(10),
        'completed' => true,
        'source_app' => 'dodo',
    ]);
    // Recent session — should appear
    FastingSession::create([
        'user_id' => $user->id,
        'mode' => '16:8',
        'target_duration_minutes' => 960,
        'started_at' => now()->subDays(2)->subMinutes(60),
        'ended_at' => now()->subDays(2),
        'completed' => true,
        'source_app' => 'dodo',
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/fasting/history')
        ->assertOk();

    expect($resp->json('data'))->toHaveCount(1);
    expect($resp->json('meta.history_capped_days'))->toBe(7);
});

it('history shows full range for paid users', function () {
    $user = User::factory()->create(['membership_tier' => 'fp_lifetime']);

    FastingSession::create([
        'user_id' => $user->id,
        'mode' => '16:8',
        'target_duration_minutes' => 960,
        'started_at' => now()->subDays(30)->subMinutes(60),
        'ended_at' => now()->subDays(30),
        'completed' => true,
        'source_app' => 'dodo',
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/fasting/history')
        ->assertOk();

    expect($resp->json('data'))->toHaveCount(1);
    expect($resp->json('meta.history_capped_days'))->toBeNull();
});

it('all 4 endpoints reject unauthenticated requests', function () {
    $this->postJson('/api/fasting/start', ['mode' => '16:8'])->assertStatus(401);
    $this->postJson('/api/fasting/end')->assertStatus(401);
    $this->getJson('/api/fasting/current')->assertStatus(401);
    $this->getJson('/api/fasting/history')->assertStatus(401);
});
