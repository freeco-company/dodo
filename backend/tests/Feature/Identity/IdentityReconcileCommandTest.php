<?php

use App\Models\DodoUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_core.base_url', 'https://identity.test');
    config()->set('services.pandora_core.internal_secret', 'test-secret');
    Cache::forget('identity:reconcile:cursor');
});

it('errors out when env not configured', function () {
    config()->set('services.pandora_core.base_url', '');
    expect(Artisan::call('identity:reconcile'))->toBe(1);
});

it('upserts a new user from reconcile response', function () {
    Http::fake([
        'identity.test/*' => Http::response([
            'users' => [
                [
                    'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
                    'display_name' => 'New User',
                    'status' => 'active',
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
            'next_cursor' => null,
            'has_more' => false,
            'count' => 1,
        ], 200),
    ]);

    $exit = Artisan::call('identity:reconcile');

    expect($exit)->toBe(0);
    $row = DodoUser::find('aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001');
    expect($row)->not()->toBeNull()
        ->and($row->display_name)->toBe('New User');
});

it('updates display_name on existing user', function () {
    DodoUser::create([
        'pandora_user_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0002',
        'display_name' => 'Old Name',
    ]);
    Http::fake([
        'identity.test/*' => Http::response([
            'users' => [
                ['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0002', 'display_name' => 'New Name', 'status' => 'active', 'updated_at' => now()->toIso8601String()],
            ],
            'next_cursor' => null, 'has_more' => false, 'count' => 1,
        ], 200),
    ]);

    Artisan::call('identity:reconcile');

    expect(DodoUser::find('aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0002')->display_name)->toBe('New Name');
});

it('paginates through has_more', function () {
    Http::fake(function ($request) {
        $url = $request->url();
        // Default since=epoch on first call; second call uses next_cursor
        if (str_contains($url, 'since=2026')) {
            return Http::response([
                'users' => [['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0004', 'display_name' => 'B', 'status' => 'active', 'updated_at' => now()->toIso8601String()]],
                'next_cursor' => null,
                'has_more' => false, 'count' => 1,
            ], 200);
        }

        return Http::response([
            'users' => [['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0003', 'display_name' => 'A', 'status' => 'active', 'updated_at' => now()->toIso8601String()]],
            'next_cursor' => '2026-04-29T08:00:00Z',
            'has_more' => true, 'count' => 1,
        ], 200);
    });

    Artisan::call('identity:reconcile');

    expect(DodoUser::count())->toBeGreaterThanOrEqual(2);
});

it('--dry-run does not write', function () {
    Http::fake([
        'identity.test/*' => Http::response([
            'users' => [['id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0005', 'display_name' => 'X', 'status' => 'active', 'updated_at' => now()->toIso8601String()]],
            'next_cursor' => null, 'has_more' => false, 'count' => 1,
        ], 200),
    ]);

    Artisan::call('identity:reconcile', ['--dry-run' => true]);

    expect(DodoUser::find('aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0005'))->toBeNull();
});

it('sends X-Pandora-Internal-Secret header', function () {
    Http::fake([
        'identity.test/*' => Http::response([
            'users' => [], 'next_cursor' => null, 'has_more' => false, 'count' => 0,
        ], 200),
    ]);

    Artisan::call('identity:reconcile');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'identity.test/api/internal/reconcile/users')
            && $request->hasHeader('X-Pandora-Internal-Secret', 'test-secret');
    });
});

it('persists cursor for next run', function () {
    Http::fake([
        'identity.test/*' => Http::response([
            'users' => [], 'next_cursor' => null, 'has_more' => false, 'count' => 0,
        ], 200),
    ]);

    Artisan::call('identity:reconcile');

    expect(Cache::get('identity:reconcile:cursor'))->not()->toBeNull();
});

it('--since override skips persisted cursor', function () {
    Cache::forever('identity:reconcile:cursor', '2025-01-01T00:00:00Z');
    Http::fake(fn ($request) => Http::response([
        'users' => [], 'next_cursor' => null, 'has_more' => false, 'count' => 0,
    ], 200));

    Artisan::call('identity:reconcile', ['--since' => '2026-04-29T00:00:00Z']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'since=2026-04-29T00%3A00%3A00Z'));
});

it('non-2xx response fails the command', function () {
    Http::fake([
        'identity.test/*' => Http::response(['detail' => 'unauthorized'], 401),
    ]);

    expect(Artisan::call('identity:reconcile'))->toBe(1);
});
