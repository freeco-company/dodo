<?php

use App\Services\Conversion\LifecycleClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.pandora_conversion.base_url', 'https://conversion.test');
    config()->set('services.pandora_conversion.shared_secret', 'test-secret');
    Cache::flush();
});

it('forget() clears the cached stage so the next call hits HTTP', function () {
    $uuid = 'uuid-forget-1';
    Http::fake([
        '*/api/v1/users/*/lifecycle' => Http::sequence()
            ->push(['stage' => 'visitor'], 200)
            ->push(['stage' => 'loyalist'], 200),
    ]);

    $client = app(LifecycleClient::class);

    expect($client->getStatus($uuid))->toBe('visitor');
    // Second call without forget → cached, still 'visitor' (only 1 HTTP call).
    expect($client->getStatus($uuid))->toBe('visitor');

    $client->forget($uuid);

    // After forget, second HTTP response in the sequence kicks in.
    expect($client->getStatus($uuid))->toBe('loyalist');
    Http::assertSentCount(2);
});

it('forget() with empty uuid is a no-op (no exception)', function () {
    $client = app(LifecycleClient::class);
    $client->forget('');
    expect(true)->toBeTrue();
});

it('getStatus(bypassCache: true) ignores cache and overwrites it', function () {
    $uuid = 'uuid-bypass-1';
    $client = app(LifecycleClient::class);
    Cache::put($client->cacheKey($uuid), 'visitor', 3600);

    Http::fake([
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'loyalist'], 200),
    ]);

    $stage = $client->getStatus($uuid, bypassCache: true);

    expect($stage)->toBe('loyalist');
    // Cache should now hold the fresh value too, so subsequent default getStatus is fast.
    expect(Cache::get($client->cacheKey($uuid)))->toBe('loyalist');
    Http::assertSentCount(1);
});

it('getStatus(bypassCache: true) still falls back to visitor on 5xx without poisoning cache with stale "real" value', function () {
    $uuid = 'uuid-bypass-5xx';
    $client = app(LifecycleClient::class);
    // Pre-existing (correct) value.
    Cache::put($client->cacheKey($uuid), 'loyalist', 3600);

    Http::fake([
        '*/api/v1/users/*/lifecycle' => Http::response(['error' => 'boom'], 503),
    ]);

    $stage = $client->getStatus($uuid, bypassCache: true);

    // bypass returned the fallback...
    expect($stage)->toBe('visitor');
    // ...and overwrote cache with the fallback. This is intentional: bypassCache means
    // "I want fresh truth from py-service" — if we couldn't get it, we shouldn't keep
    // serving the old cached value to subsequent readers either.
    expect(Cache::get($client->cacheKey($uuid)))->toBe('visitor');
});

it('getStatus default (bypassCache: false) keeps backward-compatible single-arg behaviour', function () {
    Http::fake([
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'loyalist'], 200),
    ]);

    $client = app(LifecycleClient::class);

    expect($client->getStatus('uuid-default'))->toBe('loyalist');
});
