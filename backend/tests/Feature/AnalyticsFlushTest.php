<?php

use App\Models\User;
use App\Services\AnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('flush is no-op when POSTHOG_API_KEY missing', function () {
    config(['services.posthog.api_key' => '']);

    $svc = app(AnalyticsService::class);
    $user = User::factory()->create();
    $svc->track($user, 'test.event', ['k' => 'v']);

    expect($svc->flush())->toBe(0);
    // Row remains unsent so the next configured flush picks it up.
    $row = DB::table('analytics_events')->first();
    expect((bool) $row->sent_to_provider)->toBeFalse();
});

it('flush forwards events and marks them sent when key set', function () {
    config([
        'services.posthog.api_key' => 'phc_test_dummy',
        'services.posthog.host' => 'https://posthog.test',
    ]);
    Http::fake([
        'posthog.test/batch/' => Http::response(['status' => 1], 200),
    ]);

    $svc = app(AnalyticsService::class);
    $user = User::factory()->create();
    $svc->track($user, 'a', ['x' => 1]);
    $svc->track($user, 'b', ['x' => 2]);

    expect($svc->flush())->toBe(2);
    $unsent = DB::table('analytics_events')->where('sent_to_provider', false)->count();
    expect($unsent)->toBe(0);

    Http::assertSent(function ($req) {
        return str_contains($req->url(), '/batch/')
            && is_array($req['batch'])
            && count($req['batch']) === 2;
    });
});

it('flush leaves rows unsent on PostHog 5xx', function () {
    config([
        'services.posthog.api_key' => 'phc_test_dummy',
        'services.posthog.host' => 'https://posthog.test',
    ]);
    Http::fake([
        'posthog.test/batch/' => Http::response('boom', 503),
    ]);

    $svc = app(AnalyticsService::class);
    $user = User::factory()->create();
    $svc->track($user, 'a');

    expect($svc->flush())->toBe(0);
    expect(DB::table('analytics_events')->where('sent_to_provider', false)->count())->toBe(1);
});
