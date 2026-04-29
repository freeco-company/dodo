<?php

use App\Jobs\PublishGamificationEventJob;
use App\Services\Gamification\GamificationPublisher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
});

it('dispatches a job when configured with a known event_kind', function () {
    Bus::fake();
    app(GamificationPublisher::class)->publish(
        '00000000-0000-0000-0000-000000000001',
        'meal.meal_logged',
        'meal.meal_logged.42',
        ['meal_id' => 42],
    );
    Bus::assertDispatched(PublishGamificationEventJob::class, function (PublishGamificationEventJob $job) {
        return $job->body['event_kind'] === 'meal.meal_logged'
            && $job->body['source_app'] === 'meal'
            && $job->body['idempotency_key'] === 'meal.meal_logged.42'
            && $job->body['pandora_user_uuid'] === '00000000-0000-0000-0000-000000000001';
    });
});

it('drops unknown event_kind without dispatching', function () {
    Bus::fake();
    app(GamificationPublisher::class)->publish(
        'uuid-x',
        'meal.never_heard_of_this',
        'k1',
    );
    Bus::assertNotDispatched(PublishGamificationEventJob::class);
});

it('noops when base_url is missing', function () {
    config()->set('services.pandora_gamification.base_url', '');
    Bus::fake();
    app(GamificationPublisher::class)->publish(
        'uuid-x',
        'meal.app_opened',
        'k1',
    );
    Bus::assertNotDispatched(PublishGamificationEventJob::class);
});

it('noops when shared_secret is missing', function () {
    config()->set('services.pandora_gamification.shared_secret', '');
    Bus::fake();
    app(GamificationPublisher::class)->publish('uuid-x', 'meal.app_opened', 'k1');
    Bus::assertNotDispatched(PublishGamificationEventJob::class);
});

it('drops events with empty uuid or idempotency_key', function () {
    Bus::fake();
    app(GamificationPublisher::class)->publish('', 'meal.app_opened', 'k1');
    app(GamificationPublisher::class)->publish('uuid-x', 'meal.app_opened', '');
    Bus::assertNotDispatched(PublishGamificationEventJob::class);
});

it('isEnabled reflects config presence', function () {
    expect(app(GamificationPublisher::class)->isEnabled())->toBeTrue();
    config()->set('services.pandora_gamification.base_url', '');
    expect(app(GamificationPublisher::class)->isEnabled())->toBeFalse();
});

it('send() POSTs to py-service with the correct headers and body', function () {
    Http::fake([
        'gamification.test/*' => Http::response([
            'id' => 1,
            'xp_delta' => 5,
            'total_xp' => 5,
            'group_level' => 1,
            'leveled_up_to' => null,
            'duplicate' => false,
        ], 201),
    ]);

    app(GamificationPublisher::class)->send([
        'pandora_user_uuid' => '00000000-0000-0000-0000-000000000001',
        'source_app' => 'meal',
        'event_kind' => 'meal.meal_logged',
        'idempotency_key' => 'meal.meal_logged.42',
        'occurred_at' => '2026-04-29T00:00:00+00:00',
        'metadata' => [],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://gamification.test/api/v1/internal/gamification/events'
            && $request->hasHeader('X-Internal-Secret', 'test-secret')
            && $request['event_kind'] === 'meal.meal_logged'
            && $request['source_app'] === 'meal';
    });
});

it('send() throws on non-2xx so the queue retries', function () {
    Http::fake([
        'gamification.test/*' => Http::response(['detail' => 'unknown event_kind'], 422),
    ]);

    expect(fn () => app(GamificationPublisher::class)->send([
        'pandora_user_uuid' => '00000000-0000-0000-0000-000000000001',
        'source_app' => 'meal',
        'event_kind' => 'meal.app_opened',
        'idempotency_key' => 'k',
        'occurred_at' => '2026-04-29T00:00:00+00:00',
        'metadata' => [],
    ]))->toThrow(\RuntimeException::class);
});

it('strips null metadata values before queueing', function () {
    Bus::fake();
    app(GamificationPublisher::class)->publish(
        '00000000-0000-0000-0000-000000000001',
        'meal.meal_logged',
        'k1',
        ['meal_id' => 7, 'note' => null, 'tag' => 'x'],
    );
    Bus::assertDispatched(PublishGamificationEventJob::class, function (PublishGamificationEventJob $job) {
        $meta = $job->body['metadata'];

        return $meta === ['meal_id' => 7, 'tag' => 'x'];
    });
});

it('catalog whitelist matches catalog §3.1 (no strays, no missing core events)', function () {
    expect(GamificationPublisher::KNOWN_EVENT_KINDS)
        ->toContain('meal.meal_logged')
        ->toContain('meal.streak_7')
        ->toContain('meal.card_first_solve')
        ->toContain('meal.app_opened');

    foreach (GamificationPublisher::KNOWN_EVENT_KINDS as $kind) {
        expect($kind)->toStartWith('meal.');
    }
});
