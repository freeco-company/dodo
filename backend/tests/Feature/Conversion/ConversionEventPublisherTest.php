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

it('engagement.deep semantic is now 14 days (ADR-008 §2.2) — payload streak_days 14 still dispatches', function () {
    Bus::fake();
    app(ConversionEventPublisher::class)->publish(
        '00000000-0000-0000-0000-0000000000ee',
        'engagement.deep',
        ['streak_days' => 14, 'reason' => 'demo_seeded_streak'],
    );
    Bus::assertDispatched(PublishConversionEventJob::class, function (PublishConversionEventJob $job) {
        return $job->body['event_type'] === 'engagement.deep'
            && $job->body['payload']['streak_days'] === 14;
    });
});

it('mothership.first_order clears the lifecycle cache (applicant → franchisee_self_use, ADR-008 §2.3)', function () {
    Bus::fake();
    $uuid = '00000000-0000-0000-0000-000000000101';
    $key = app(LifecycleClient::class)->cacheKey($uuid);
    Cache::put($key, 'applicant', 3600);

    app(ConversionEventPublisher::class)->publish($uuid, 'mothership.first_order', [
        'order_id' => 'JS-12345',
        'amount_twd' => 6600,
    ]);

    expect(Cache::has($key))->toBeFalse();
    Bus::assertDispatched(PublishConversionEventJob::class);
});

it('mothership.consultation_submitted clears the lifecycle cache (loyalist → applicant)', function () {
    Bus::fake();
    $uuid = '00000000-0000-0000-0000-000000000102';
    $key = app(LifecycleClient::class)->cacheKey($uuid);
    Cache::put($key, 'loyalist', 3600);

    app(ConversionEventPublisher::class)->publish($uuid, 'mothership.consultation_submitted', [
        'form_id' => 'consult-form-v2',
    ]);

    expect(Cache::has($key))->toBeFalse();
    Bus::assertDispatched(PublishConversionEventJob::class);
});

it('academy.operator_portal_click clears cache (franchisee_self_use → franchisee_active, ADR-008 §2.3)', function () {
    Bus::fake();
    $uuid = '00000000-0000-0000-0000-000000000103';
    $key = app(LifecycleClient::class)->cacheKey($uuid);
    Cache::put($key, 'franchisee_self_use', 3600);

    app(ConversionEventPublisher::class)->publish($uuid, 'academy.operator_portal_click', [
        'source' => 'academy_home',
    ]);

    expect(Cache::has($key))->toBeFalse();
});

it('drops academy.training_progress (ADR-008 §3.2 retired event) — no dispatch, no cache touch', function () {
    Bus::fake();
    $uuid = '00000000-0000-0000-0000-000000000104';
    $key = app(LifecycleClient::class)->cacheKey($uuid);
    Cache::put($key, 'applicant', 3600);

    app(ConversionEventPublisher::class)->publish($uuid, 'academy.training_progress', [
        'lesson_id' => 'foo',
    ]);

    // ADR-008 §3.2: training progress 訊號源作廢（3.5hr Zoom 課人工帶，系統不碰）。
    Bus::assertNotDispatched(PublishConversionEventJob::class);
    // Cache 也不應被誤清 — 此事件純粹丟掉。
    expect(Cache::get($key))->toBe('applicant');
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
