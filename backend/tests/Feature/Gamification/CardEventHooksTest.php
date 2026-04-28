<?php

use App\Jobs\PublishGamificationEventJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('db:seed', ['--force' => true]);
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
    Bus::fake();
});

// `correctChoiceIdxForCard($cardId)` lives in tests/Pest.php

it('fires dodo.card_correct AND dodo.card_first_solve on a correct answer', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccc1111-1111-1111-1111-111111111111',
        'current_streak' => 0,
        'level' => 5,
    ]);

    $draw = $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/scene-draw', ['card_id' => 'fm-egg'])
        ->assertOk();
    $playId = (int) $draw->json('play_id');

    $idx = correctChoiceIdxForCard('fm-egg');
    expect($idx)->not->toBeNull();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/answer', ['play_id' => $playId, 'choice_idx' => $idx])
        ->assertOk();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'dodo.card_correct'
            && $job->body['source_app'] === 'dodo';
    });
    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) {
        return $job->body['event_kind'] === 'dodo.card_first_solve'
            && str_starts_with($job->body['idempotency_key'], 'dodo.card_first_solve.cccc1111-');
    });
});

it('does not fire card_correct/first_solve on a wrong answer', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccc2222-2222-2222-2222-222222222222',
        'current_streak' => 0,
        'level' => 5,
    ]);

    $draw = $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/scene-draw', ['card_id' => 'fm-egg'])
        ->assertOk();
    $playId = (int) $draw->json('play_id');

    $correctIdx = correctChoiceIdxForCard('fm-egg');
    $wrongIdx = $correctIdx === 0 ? 1 : 0;

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/answer', ['play_id' => $playId, 'choice_idx' => $wrongIdx])
        ->assertOk();

    Bus::assertNotDispatched(
        PublishGamificationEventJob::class,
        fn ($job) => in_array($job->body['event_kind'] ?? '', ['dodo.card_correct', 'dodo.card_first_solve'], true),
    );
});

it('idempotency_key for card_correct uses the play id', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccc3333-3333-3333-3333-333333333333',
        'current_streak' => 0,
        'level' => 5,
    ]);

    $draw = $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/scene-draw', ['card_id' => 'fm-egg'])
        ->assertOk();
    $playId = (int) $draw->json('play_id');
    $idx = correctChoiceIdxForCard('fm-egg');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/answer', ['play_id' => $playId, 'choice_idx' => $idx])
        ->assertOk();

    Bus::assertDispatched(PublishGamificationEventJob::class, function ($job) use ($playId) {
        return $job->body['event_kind'] === 'dodo.card_correct'
            && $job->body['idempotency_key'] === "dodo.card_correct.{$playId}";
    });
});

it('publisher noops when env not configured', function () {
    config()->set('services.pandora_gamification.base_url', '');
    $user = User::factory()->create([
        'pandora_user_uuid' => 'cccc4444-4444-4444-4444-444444444444',
        'current_streak' => 0,
        'level' => 5,
    ]);

    $draw = $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/scene-draw', ['card_id' => 'fm-egg'])
        ->assertOk();
    $playId = (int) $draw->json('play_id');
    $idx = correctChoiceIdxForCard('fm-egg');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/answer', ['play_id' => $playId, 'choice_idx' => $idx])
        ->assertOk();

    Bus::assertNotDispatched(PublishGamificationEventJob::class);
});
