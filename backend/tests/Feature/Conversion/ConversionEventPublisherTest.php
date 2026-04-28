<?php

use App\Jobs\PublishConversionEventJob;
use App\Services\Conversion\ConversionEventPublisher;
use App\Services\Conversion\LifecycleClient;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.pandora_conversion.base_url', 'https://conversion.test');
    config()->set('services.pandora_conversion.shared_secret', 'test-secret');
    config()->set('services.pandora_conversion.app_id', 'doudou');
    Cache::flush();
});

it('dispatches a job when configured', function () {
    Bus::fake();
    app(ConversionEventPublisher::class)->publish(
        '00000000-0000-0000-0000-000000000001',
        'app.opened',
        ['source' => 'unit-test'],
    );
    Bus::assertDispatched(PublishConversionEventJob::class, function (PublishConversionEventJob $job) {
        return $job->body['event_type'] === 'app.opened'
            && $job->body['pandora_user_uuid'] === '00000000-0000-0000-0000-000000000001'
            && $job->body['app_id'] === 'doudou';
    });
});

it('noops when base_url is missing', function () {
    config()->set('services.pandora_conversion.base_url', '');
    Bus::fake();
    app(ConversionEventPublisher::class)->publish(
        '00000000-0000-0000-0000-000000000001',
        'app.opened',
    );
    Bus::assertNotDispatched(PublishConversionEventJob::class);
});

it('noops when shared_secret is missing', function () {
    config()->set('services.pandora_conversion.shared_secret', '');
    Bus::fake();
    app(ConversionEventPublisher::class)->publish('uuid-x', 'app.opened');
    Bus::assertNotDispatched(PublishConversionEventJob::class);
});

it('drops events with empty uuid', function () {
    Bus::fake();
    app(ConversionEventPublisher::class)->publish('', 'app.opened');
    Bus::assertNotDispatched(PublishConversionEventJob::class);
});

it('send() POSTs to py-service with the correct headers and body', function () {
    Http::fake([
        'conversion.test/*' => Http::response(['id' => 1, 'lifecycle_transition' => 'registered'], 201),
    ]);

    app(ConversionEventPublisher::class)->send([
        'pandora_user_uuid' => '00000000-0000-0000-0000-000000000001',
        'app_id' => 'doudou',
        'event_type' => 'app.opened',
        'payload' => (object) [],
        'occurred_at' => '2026-04-28T00:00:00+00:00',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://conversion.test/api/v1/internal/events'
            && $request->hasHeader('X-Internal-Secret', 'test-secret')
            && $request['event_type'] === 'app.opened';
    });
});

it('engagement.deep clears the lifecycle cache so next bootstrap re-fetches', function () {
    Bus::fake();
    $uuid = '00000000-0000-0000-0000-0000000000aa';
    $key = app(LifecycleClient::class)->cacheKey($uuid);
    Cache::put($key, 'engaged', 3600);
    expect(Cache::get($key))->toBe('engaged');

    app(ConversionEventPublisher::class)->publish($uuid, 'engagement.deep');

    expect(Cache::has($key))->toBeFalse();
    Bus::assertDispatched(PublishConversionEventJob::class);
});

it('franchise.cta_click clears the lifecycle cache (loyalist → applicant transition)', function () {
    Bus::fake();
    $uuid = '00000000-0000-0000-0000-0000000000bb';
    $key = app(LifecycleClient::class)->cacheKey($uuid);
    Cache::put($key, 'loyalist', 3600);

    app(ConversionEventPublisher::class)->publish($uuid, 'franchise.cta_click');

    expect(Cache::has($key))->toBeFalse();
});

it('app.opened does NOT clear the lifecycle cache (avoid thrashing on every bootstrap)', function () {
    Bus::fake();
    $uuid = '00000000-0000-0000-0000-0000000000cc';
    $key = app(LifecycleClient::class)->cacheKey($uuid);
    Cache::put($key, 'loyalist', 3600);

    app(ConversionEventPublisher::class)->publish($uuid, 'app.opened');

    // app.opened fires every bootstrap — if it cleared cache the cache would be useless.
    expect(Cache::get($key))->toBe('loyalist');
});

it('franchise.cta_view does NOT clear the cache (observation-only, no transition)', function () {
    Bus::fake();
    $uuid = '00000000-0000-0000-0000-0000000000dd';
    $key = app(LifecycleClient::class)->cacheKey($uuid);
    Cache::put($key, 'loyalist', 3600);

    app(ConversionEventPublisher::class)->publish($uuid, 'franchise.cta_view');

    expect(Cache::get($key))->toBe('loyalist');
});

it('send() throws on failed response (so queue worker retries)', function () {
    Http::fake([
        'conversion.test/*' => Http::response(['detail' => 'oops'], 500),
    ]);

    expect(fn () => app(ConversionEventPublisher::class)->send([
        'pandora_user_uuid' => 'uuid-x',
        'app_id' => 'doudou',
        'event_type' => 'app.opened',
        'payload' => (object) [],
        'occurred_at' => '2026-04-28T00:00:00+00:00',
    ]))->toThrow(RuntimeException::class);
});
