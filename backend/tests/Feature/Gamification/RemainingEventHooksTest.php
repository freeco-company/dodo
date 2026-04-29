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

// ── meal.weight_logged ────────────────────────────────────────────────

it('fires meal.weight_logged on the first weight log of the day', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'wwww1111-1111-1111-1111-111111111111',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/weight', ['weight_kg' => 62.5])
        ->assertOk();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'meal.weight_logged'
            && $job->body['source_app'] === 'meal'
            && $job->body['metadata']['weight_kg'] === 62.5;
    });
});

it('does NOT re-fire weight_logged on a same-day overwrite', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'wwww2222-2222-2222-2222-222222222222',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/weight', ['weight_kg' => 60.0])
        ->assertOk();
    Bus::fake();  // reset

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/weight', ['weight_kg' => 59.5])
        ->assertOk();

    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.weight_logged',
    );
});

it('idempotency_key for weight_logged is per-(uuid, date)', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'wwww3333-3333-3333-3333-333333333333',
    ]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/weight', ['weight_kg' => 70])
        ->assertOk();

    $today = Carbon::today()->toDateString();
    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) use ($today) {
        return $job->body['event_kind'] === 'meal.weight_logged'
            && str_ends_with($job->body['idempotency_key'], $today);
    });
});

// ── meal.chat_daily ───────────────────────────────────────────────────

it('fires meal.chat_daily on POST /api/chat/message', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccc1111-aaaa-bbbb-cccc-111111111111',
    ]);

    // chat endpoint may 503 (no AI service in test) — that's fine, we still
    // fire the gamification event before calling AI.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/chat/message', ['content' => 'hi mentor', 'scenario' => 'test'])
        ->assertStatus(503);

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'meal.chat_daily'
            && $job->body['metadata']['scenario'] === 'test';
    });
});

it('chat_daily idempotency_key is per-(uuid, date) so multiple chats only credit once on server', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccc2222-aaaa-bbbb-cccc-222222222222',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/chat/message', ['content' => 'one']);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/chat/message', ['content' => 'two']);

    $today = Carbon::today()->toDateString();
    $events = collect(Bus::dispatched(PublishGamificationEventJob::class))
        ->filter(fn ($job) => ($job->body['event_kind'] ?? '') === 'meal.chat_daily');
    expect($events->count())->toBe(2);
    foreach ($events as $job) {
        expect(str_ends_with($job->body['idempotency_key'], $today))->toBeTrue();
    }
});

// ── meal.weekly_review_read ──────────────────────────────────────────

it('fires meal.weekly_review_read when the week has enough logged days', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'rrrr1111-aaaa-bbbb-cccc-111111111111',
    ]);
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    // 3 logged days ≥ has_enough_data threshold
    for ($i = 0; $i < 3; $i++) {
        DailyLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $weekStart->copy()->addDays($i)->toDateString(),
            'meals_logged' => 1,
            'total_score' => 50,
        ]);
    }

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/reports/weekly/'.$weekStart->toDateString())
        ->assertOk();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) use ($weekStart) {
        return $job->body['event_kind'] === 'meal.weekly_review_read'
            && str_ends_with(
                $job->body['idempotency_key'],
                $weekStart->toDateString()
            );
    });
});

it('does NOT fire weekly_review_read when the week has too few logged days', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'rrrr2222-aaaa-bbbb-cccc-222222222222',
    ]);
    $weekStart = Carbon::today()->startOfWeek(Carbon::MONDAY);
    // Only 2 days — below threshold
    for ($i = 0; $i < 2; $i++) {
        DailyLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $weekStart->copy()->addDays($i)->toDateString(),
            'meals_logged' => 1,
            'total_score' => 50,
        ]);
    }

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/reports/weekly/'.$weekStart->toDateString())
        ->assertOk();

    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => $job->body['event_kind'] === 'meal.weekly_review_read',
    );
});

// ── env-disabled noop ────────────────────────────────────────────────

it('publishes nothing when env is not configured', function () {
    config()->set('services.pandora_gamification.base_url', '');
    $user = User::factory()->create([
        'pandora_user_uuid' => 'eeee9999-aaaa-bbbb-cccc-999999999999',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/weight', ['weight_kg' => 60]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/chat/message', ['content' => 'x']);

    Bus::assertNotDispatched(PublishGamificationEventJob::class);
});
