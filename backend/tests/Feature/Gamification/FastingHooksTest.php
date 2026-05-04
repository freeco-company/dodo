<?php

use App\Jobs\PublishAchievementAwardJob;
use App\Jobs\PublishGamificationEventJob;
use App\Models\FastingSession;
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
 * SPEC-fasting-timer Phase 2 — gamification + achievement publishing on
 * completed fasting sessions.
 */
function seedActiveFasting(User $user, int $minutesAgo, int $targetMinutes = 960): FastingSession
{
    return FastingSession::create([
        'user_id' => $user->id,
        'mode' => '16:8',
        'target_duration_minutes' => $targetMinutes,
        'started_at' => now()->subMinutes($minutesAgo),
        'source_app' => 'dodo',
    ]);
}

it('publishes meal.fasting_completed when a session ends above target', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaa1111-1111-1111-1111-111111111111',
    ]);
    seedActiveFasting($user, 970);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/end')
        ->assertOk()
        ->assertJsonPath('session.completed', true);

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'meal.fasting_completed'
            && $job->body['source_app'] === 'meal'
            && $job->body['metadata']['mode'] === '16:8';
    });
});

it('does NOT publish when ended below target', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'bbbb1111-1111-1111-1111-111111111111',
    ]);
    seedActiveFasting($user, 120);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/end')
        ->assertOk()
        ->assertJsonPath('session.completed', false);

    // Scope assertion to fasting_completed — the daily-login-streak middleware
    // may legitimately dispatch streak / milestone-unlock jobs on the same
    // request, which is unrelated to the fasting failure path under test.
    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => ($job->body['event_kind'] ?? '') === 'meal.fasting_completed',
    );
});

it('awards meal.fasting_first achievement on the very first completion', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccc1111-1111-1111-1111-111111111111',
    ]);
    seedActiveFasting($user, 970);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/end')
        ->assertOk();

    Bus::assertDispatched(PublishAchievementAwardJob::class, function ($job) {
        return $job->body['code'] === 'meal.fasting_first';
    });
});

it('does NOT re-award fasting_first on the second completion', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'dddd1111-1111-1111-1111-111111111111',
    ]);
    // Pre-seed one already completed session
    FastingSession::create([
        'user_id' => $user->id,
        'mode' => '16:8',
        'target_duration_minutes' => 960,
        'started_at' => now()->subDays(1)->subMinutes(970),
        'ended_at' => now()->subDays(1),
        'completed' => true,
        'source_app' => 'dodo',
    ]);
    seedActiveFasting($user, 970);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/end')
        ->assertOk();

    Bus::assertNotDispatched(PublishAchievementAwardJob::class, function ($job) {
        return $job->body['code'] === 'meal.fasting_first';
    });
});

it('uses session id as the gamification idempotency key', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'eeee1111-1111-1111-1111-111111111111',
    ]);
    $session = seedActiveFasting($user, 970);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/fasting/end')
        ->assertOk();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) use ($session) {
        return $job->body['event_kind'] === 'meal.fasting_completed'
            && $job->body['idempotency_key'] === "meal.fasting_completed.{$session->id}";
    });
});
