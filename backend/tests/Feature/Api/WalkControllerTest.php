<?php

use App\Jobs\PublishGamificationEventJob;
use App\Models\DailyWalkSession;
use App\Models\Meal;
use App\Models\MiniDodoCollection;
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
 * SPEC-pikmin-walk-v1 — Pikmin Bloom 風計步系統 endpoint test。
 */

it('returns empty today state for new user', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'walk-1111-1111-1111-1111-111111111111']);

    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/walk/today');
    $resp->assertOk()
        ->assertJsonPath('data.total_steps', 0)
        ->assertJsonPath('data.phase', 'seed')
        ->assertJsonPath('data.goal_steps', 8000)
        ->assertJsonPath('data.collected', [])
        ->assertJsonPath('data.collected_color_count', 0);
});

it('syncs steps and advances phase', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'walk-2222-2222-2222-2222-222222222222']);

    // Sync at 3000 → sprout
    $r1 = $this->actingAs($user, 'sanctum')
        ->postJson('/api/walk/sync', ['total_steps' => 3000]);
    $r1->assertOk()
        ->assertJsonPath('data.phase', 'sprout')
        ->assertJsonPath('data.phase_advanced', true)
        ->assertJsonPath('data.goal_published_now', false);

    // Sync at 6000 → bloom (summon blue mini-dodo)
    $r2 = $this->actingAs($user, 'sanctum')
        ->postJson('/api/walk/sync', ['total_steps' => 6000]);
    $r2->assertOk()
        ->assertJsonPath('data.phase', 'bloom')
        ->assertJsonPath('data.phase_advanced', true);
    $blueCount = MiniDodoCollection::where('user_id', $user->id)->where('color', 'blue')->count();
    expect($blueCount)->toBe(1);
});

it('publishes meal.steps_goal_achieved exactly once when reaching fruit phase', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'walk-3333-3333-3333-3333-333333333333']);

    // Cross the fruit threshold
    $r = $this->actingAs($user, 'sanctum')
        ->postJson('/api/walk/sync', ['total_steps' => 8500]);
    $r->assertOk()
        ->assertJsonPath('data.phase', 'fruit')
        ->assertJsonPath('data.goal_published_now', true);

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return ($job->body['event_kind'] ?? null) === 'meal.steps_goal_achieved';
    });

    Bus::fake(); // Reset to verify second sync does not re-publish

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/walk/sync', ['total_steps' => 9000])
        ->assertOk()
        ->assertJsonPath('data.goal_published_now', false);

    Bus::assertNotDispatched(PublishGamificationEventJob::class);
});

it('does not downgrade total_steps on stale resync', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'walk-4444-4444-4444-4444-444444444444']);
    $this->actingAs($user, 'sanctum')->postJson('/api/walk/sync', ['total_steps' => 7000])->assertOk();
    $this->actingAs($user, 'sanctum')->postJson('/api/walk/sync', ['total_steps' => 5000])->assertOk();

    $session = DailyWalkSession::where('user_id', $user->id)->first();
    expect($session)->not->toBeNull();
    expect($session->total_steps)->toBe(7000);
});

it('summons macro mini-dodo from meal log', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'walk-5555-5555-5555-5555-555555555555']);
    $today = now()->toDateString();

    // Build a high-protein + high-fiber day
    Meal::factory()->create([
        'user_id' => $user->id,
        'date' => $today,
        'protein_g' => 60,
        'carbs_g' => 120,
        'fat_g' => 30,
        'fiber_g' => 18,
    ]);

    /** @var \App\Services\Dodo\Walk\WalkSessionService $svc */
    $svc = app(\App\Services\Dodo\Walk\WalkSessionService::class);
    $newly = $svc->summonForMealsToday($user, now());

    $colors = collect($newly)->pluck('color')->all();
    expect($colors)->toContain('red');   // protein 60 ≥ 50
    expect($colors)->toContain('green'); // fiber 18 ≥ 15
    expect($colors)->toContain('yellow'); // fat 30 in [25..80]
    expect($colors)->toContain('purple'); // carbs 120 ≥ 100

    // Idempotent: re-call same day same meals → no new summons
    $newly2 = $svc->summonForMealsToday($user, now());
    expect($newly2)->toBe([]);
});

it('does not summon macro mini-dodo when below threshold', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'walk-6666-6666-6666-6666-666666666666']);
    $today = now()->toDateString();

    Meal::factory()->create([
        'user_id' => $user->id,
        'date' => $today,
        'protein_g' => 10,  // below 50
        'fiber_g' => 3,     // below 15
        'fat_g' => 5,       // below 25
        'carbs_g' => 30,    // below 100
    ]);

    /** @var \App\Services\Dodo\Walk\WalkSessionService $svc */
    $svc = app(\App\Services\Dodo\Walk\WalkSessionService::class);
    $newly = $svc->summonForMealsToday($user, now());

    expect($newly)->toBe([]);
});

it('returns history with N days padded to zero', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'walk-7777-7777-7777-7777-777777777777']);
    $this->actingAs($user, 'sanctum')->postJson('/api/walk/sync', ['total_steps' => 4000])->assertOk();

    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/walk/history?days=7');
    $resp->assertOk();
    $data = $resp->json('data');
    expect($data)->toHaveCount(7);

    $last = end($data);
    expect($last['steps'])->toBe(4000);
    expect($last['phase'])->toBe('sprout');
});

it('returns deterministic stub diary for free user without ai-service', function () {
    config()->set('services.ai_service.base_url', '');
    $user = User::factory()->create(['pandora_user_uuid' => 'walk-8888-8888-8888-8888-888888888888']);

    $this->actingAs($user, 'sanctum')->postJson('/api/walk/sync', ['total_steps' => 3000])->assertOk();
    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/walk/diary');

    $resp->assertOk()
        ->assertJsonPath('data.stub_mode', true)
        ->assertJsonPath('data.payload.phase', 'sprout');
    expect($resp->json('data.narrative.headline'))->toBeString();
});

it('rejects invalid step counts', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/walk/sync', ['total_steps' => -5])
        ->assertStatus(422);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/walk/sync', ['total_steps' => 999999])
        ->assertStatus(422);
});

it('requires sanctum auth', function () {
    $this->getJson('/api/walk/today')->assertUnauthorized();
    $this->postJson('/api/walk/sync', ['total_steps' => 1000])->assertUnauthorized();
});
