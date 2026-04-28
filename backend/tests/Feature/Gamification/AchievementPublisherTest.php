<?php

use App\Jobs\PublishAchievementAwardJob;
use App\Services\Gamification\AchievementPublisher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
});

it('dispatches a job for a known achievement code', function () {
    Bus::fake();
    app(AchievementPublisher::class)->publish(
        '00000000-0000-0000-0000-000000000001',
        'dodo.streak_7',
        'dodo.streak_7.00000000-0000-0000-0000-000000000001',
        ['streak_days' => 7],
    );
    Bus::assertDispatched(PublishAchievementAwardJob::class, function (PublishAchievementAwardJob $job) {
        return $job->body['code'] === 'dodo.streak_7'
            && $job->body['source_app'] === 'dodo'
            && $job->body['idempotency_key'] === 'dodo.streak_7.00000000-0000-0000-0000-000000000001';
    });
});

it('drops unknown achievement codes', function () {
    Bus::fake();
    app(AchievementPublisher::class)->publish('uuid-x', 'dodo.never_existed', 'k1');
    Bus::assertNotDispatched(PublishAchievementAwardJob::class);
});

it('noops when env not configured', function () {
    config()->set('services.pandora_gamification.base_url', '');
    Bus::fake();
    app(AchievementPublisher::class)->publish('uuid-x', 'dodo.first_meal', 'k1');
    Bus::assertNotDispatched(PublishAchievementAwardJob::class);
});

it('drops events with empty uuid or idempotency_key', function () {
    Bus::fake();
    app(AchievementPublisher::class)->publish('', 'dodo.first_meal', 'k1');
    app(AchievementPublisher::class)->publish('uuid-x', 'dodo.first_meal', '');
    Bus::assertNotDispatched(PublishAchievementAwardJob::class);
});

it('send() POSTs to py-service /achievements/award with HMAC header', function () {
    Http::fake([
        'gamification.test/*' => Http::response([
            'awarded' => true, 'code' => 'dodo.streak_7', 'tier' => 'silver',
            'xp_delta' => 100, 'total_xp' => 100, 'group_level' => 2,
        ], 201),
    ]);

    app(AchievementPublisher::class)->send([
        'pandora_user_uuid' => '00000000-0000-0000-0000-000000000001',
        'code' => 'dodo.streak_7',
        'source_app' => 'dodo',
        'idempotency_key' => 'k1',
        'occurred_at' => '2026-04-29T00:00:00+00:00',
        'metadata' => ['streak_days' => 7],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://gamification.test/api/v1/internal/gamification/achievements/award'
            && $request->hasHeader('X-Internal-Secret', 'test-secret')
            && $request['code'] === 'dodo.streak_7';
    });
});

it('send() throws on non-2xx so the queue retries', function () {
    Http::fake([
        'gamification.test/*' => Http::response(['detail' => 'unknown code'], 404),
    ]);
    expect(fn () => app(AchievementPublisher::class)->send([
        'pandora_user_uuid' => '00000000-0000-0000-0000-000000000001',
        'code' => 'dodo.first_meal',
        'source_app' => 'dodo',
        'idempotency_key' => 'k',
        'occurred_at' => '2026-04-29T00:00:00+00:00',
        'metadata' => [],
    ]))->toThrow(\RuntimeException::class);
});

it('catalog whitelist is dodo.* prefixed', function () {
    foreach (AchievementPublisher::KNOWN_ACHIEVEMENT_CODES as $code) {
        expect($code)->toStartWith('dodo.');
    }
    expect(AchievementPublisher::KNOWN_ACHIEVEMENT_CODES)
        ->toContain('dodo.first_meal')
        ->toContain('dodo.streak_7')
        ->toContain('dodo.streak_30');
});
