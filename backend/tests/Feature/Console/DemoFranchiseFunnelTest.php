<?php

use App\Models\DailyLog;
use App\Models\User;
use App\Services\Conversion\LifecycleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Tagged `demo` so it's excluded from CI's default phpunit run (see phpunit.xml).
 * Run locally via `php artisan test --group=demo` or `pest --group=demo`.
 */
beforeEach(function () {
    config()->set('services.pandora_conversion.base_url', 'https://conversion.test');
    config()->set('services.pandora_conversion.shared_secret', 'test-secret');
    config()->set('services.pandora_conversion.demo_admin_jwt', 'fake.jwt.token');
    config()->set('services.pandora_conversion.franchise_url', 'https://js-store.com.tw/franchise/consult');
    Cache::flush();
});

it('runs the happy path with --force-loyalist (everything mocked)', function () {
    Http::fake([
        // py-service event ingest (called by the queue job, sync mode)
        '*/api/v1/internal/events' => Http::response(['accepted' => true, 'id' => 1], 201),
        // force-transition admin route
        '*/api/v1/users/*/lifecycle/transition' => Http::response([
            'id' => 99, 'from_status' => 'visitor', 'to_status' => 'loyalist',
        ], 201),
        // lifecycle GET — return loyalist after we forced it
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'loyalist'], 200),
    ]);

    $exit = Artisan::call('demo:franchise-funnel', ['--force-loyalist' => true]);
    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('Demo complete');

    // user exists with uuid
    $user = User::where('email', 'demo@dodo.local')->first();
    expect($user)->not->toBeNull();
    expect($user->pandora_user_uuid)->not->toBeEmpty();

    // ADR-008 §2.1: loyalist 門檻 14 天 — demo seeder 對齊
    expect(DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)->count())->toBe(14);

    // lifecycle transition was invoked
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/lifecycle/transition')
            && $request->method() === 'POST'
            && $request['to_status'] === 'loyalist';
    });
})->group('demo');

it('--force-self-use POSTs to_status=franchisee_self_use to admin endpoint (ADR-008)', function () {
    Http::fake([
        '*/api/v1/internal/events' => Http::response(['accepted' => true], 201),
        '*/api/v1/users/*/lifecycle/transition' => Http::response([
            'to_status' => 'franchisee_self_use',
        ], 201),
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'franchisee_self_use'], 200),
    ]);

    $exit = Artisan::call('demo:franchise-funnel', ['--force-self-use' => true]);
    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('franchisee_self_use');
    // 「想擴大經營？」CTA 文案 preview 應該印出來
    expect(Artisan::output())->toContain('想擴大經營');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/lifecycle/transition')
            && $request['to_status'] === 'franchisee_self_use';
    });
})->group('demo');

it('--force-active POSTs to_status=franchisee_active and shows operator portal expectation', function () {
    Http::fake([
        '*/api/v1/internal/events' => Http::response(['accepted' => true], 201),
        '*/api/v1/users/*/lifecycle/transition' => Http::response([
            'to_status' => 'franchisee_active',
        ], 201),
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'franchisee_active'], 200),
    ]);

    $exit = Artisan::call('demo:franchise-funnel', ['--force-active' => true]);
    expect($exit)->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('franchisee_active');
    // active 不顯示 banner — 改顯示 operator portal 鉤子
    expect($output)->toContain('show_operator_portal = true');
    expect($output)->toContain('show_franchise_cta = false');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/lifecycle/transition')
            && $request['to_status'] === 'franchisee_active';
    });
})->group('demo');

it('warns but still succeeds when no --force-* is passed (visitor end-state)', function () {
    Http::fake([
        '*/api/v1/internal/events' => Http::response(['accepted' => true], 201),
        // no transition — natural lifecycle stays at visitor in this stub
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'visitor'], 200),
    ]);

    $exit = Artisan::call('demo:franchise-funnel');
    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('Without a --force-*');

    // No transition POST should have happened.
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/lifecycle/transition');
    });
})->group('demo');

it('--clean removes existing demo user and its daily_logs', function () {
    // Pre-seed a stale demo user with daily_logs and cache entries.
    $stale = User::factory()->create([
        'email' => 'demo@dodo.local',
        'pandora_user_uuid' => '99999999-9999-9999-9999-999999999999',
    ]);
    DailyLog::create([
        'user_id' => $stale->id,
        'pandora_user_uuid' => $stale->pandora_user_uuid,
        'date' => now()->toDateString(),
    ]);
    Cache::put(app(LifecycleClient::class)->cacheKey($stale->pandora_user_uuid), 'engaged', 3600);

    Http::fake([
        '*/api/v1/internal/events' => Http::response(['accepted' => true], 201),
        '*/api/v1/users/*/lifecycle/transition' => Http::response(['to_status' => 'loyalist'], 201),
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'loyalist'], 200),
    ]);

    $exit = Artisan::call('demo:franchise-funnel', [
        '--clean' => true,
        '--force-loyalist' => true,
    ]);
    expect($exit)->toBe(0);

    // The stale user must be gone (and a fresh one created with a different uuid).
    expect(User::find($stale->id))->toBeNull();

    // Stale uuid's daily_logs are gone too.
    expect(DailyLog::where('pandora_user_uuid', '99999999-9999-9999-9999-999999999999')->count())->toBe(0);

    // A new demo user exists with a different uuid.
    $fresh = User::where('email', 'demo@dodo.local')->first();
    expect($fresh)->not->toBeNull();
    expect($fresh->pandora_user_uuid)->not->toBe('99999999-9999-9999-9999-999999999999');
})->group('demo');

it('reports force failure but does not crash the demo run', function () {
    Http::fake([
        '*/api/v1/internal/events' => Http::response(['accepted' => true], 201),
        '*/api/v1/users/*/lifecycle/transition' => Http::response(['detail' => 'forbidden'], 403),
        // ADR-008: stay at visitor when transition rejected
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'visitor'], 200),
    ]);

    $exit = Artisan::call('demo:franchise-funnel', ['--force-loyalist' => true]);
    expect($exit)->toBe(0);
    expect(Artisan::output())->toContain('force-loyalist failed');
})->group('demo');
