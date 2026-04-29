<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
});

it('errors out when env not configured', function () {
    config()->set('services.pandora_gamification.base_url', '');

    $exitCode = Artisan::call('gamification:bootstrap-ledger');

    expect($exitCode)->toBe(1);
});

it('does nothing when there are no users with xp', function () {
    Http::fake();
    User::factory()->create([
        'pandora_user_uuid' => 'aaaaaaaa-1111-1111-1111-111111111111',
        'xp' => 0,
    ]);

    $exitCode = Artisan::call('gamification:bootstrap-ledger');
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Nothing to bootstrap');
    Http::assertNothingSent();
});

it('skips users without pandora_user_uuid', function () {
    Http::fake();
    // Force pandora_user_uuid to NULL after the factory + observer fills it
    $user = User::factory()->create(['xp' => 500]);
    $user->forceFill(['pandora_user_uuid' => null])->saveQuietly();
    expect($user->fresh()->pandora_user_uuid)->toBeNull();

    Artisan::call('gamification:bootstrap-ledger');

    Http::assertNothingSent();
});

it('POSTs users in batches with X-Internal-Secret header', function () {
    Http::fake([
        'gamification.test/*' => Http::response([
            'results' => [],
            'new_bootstraps' => 3,
            'skipped' => 0,
            'total_in_request' => 3,
        ], 200),
    ]);

    foreach (range(1, 3) as $i) {
        User::factory()->create([
            'pandora_user_uuid' => sprintf('aaaaaaaa-%04d-aaaa-aaaa-aaaaaaaaaaaa', $i),
            'xp' => 100 * $i,
        ]);
    }

    $exitCode = Artisan::call('gamification:bootstrap-ledger', ['--batch' => 100]);

    expect($exitCode)->toBe(0);
    Http::assertSent(function ($request) {
        return $request->url() === 'https://gamification.test/api/v1/internal/gamification/migration/bootstrap-ledger'
            && $request->hasHeader('X-Internal-Secret', 'test-secret')
            && count($request['entries']) === 3
            && $request['entries'][0]['source_app'] === 'dodo';
    });
});

it('respects --batch=2 by sending two HTTP calls for 3 users', function () {
    Http::fake([
        'gamification.test/*' => Http::response([
            'results' => [],
            'new_bootstraps' => 1,
            'skipped' => 0,
            'total_in_request' => 1,
        ], 200),
    ]);
    foreach (range(1, 3) as $i) {
        User::factory()->create([
            'pandora_user_uuid' => sprintf('bbbbbbbb-%04d-bbbb-bbbb-bbbbbbbbbbbb', $i),
            'xp' => 50 * $i,
        ]);
    }

    Artisan::call('gamification:bootstrap-ledger', ['--batch' => 2]);

    // 3 users / batch=2 → ceil = 2 HTTP calls
    Http::assertSentCount(2);
});

it('--dry-run does not call py-service', function () {
    Http::fake();
    User::factory()->create([
        'pandora_user_uuid' => 'cccccccc-1111-1111-1111-cccccccccccc',
        'xp' => 500,
    ]);

    $exitCode = Artisan::call('gamification:bootstrap-ledger', ['--dry-run' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('(dry-run)');
    Http::assertNothingSent();
});

it('--include-zero sends users with xp=0', function () {
    Http::fake([
        'gamification.test/*' => Http::response([
            'results' => [],
            'new_bootstraps' => 1,
            'skipped' => 0,
            'total_in_request' => 1,
        ], 200),
    ]);
    User::factory()->create([
        'pandora_user_uuid' => 'dddddddd-1111-1111-1111-dddddddddddd',
        'xp' => 0,
    ]);

    Artisan::call('gamification:bootstrap-ledger', ['--include-zero' => true]);

    Http::assertSent(fn ($r) => count($r['entries']) === 1
        && $r['entries'][0]['total_xp'] === 0);
});

it('--limit caps the total users processed', function () {
    Http::fake([
        'gamification.test/*' => Http::response([
            'results' => [],
            'new_bootstraps' => 2,
            'skipped' => 0,
            'total_in_request' => 2,
        ], 200),
    ]);
    foreach (range(1, 5) as $i) {
        User::factory()->create([
            'pandora_user_uuid' => sprintf('eeeeeeee-%04d-eeee-eeee-eeeeeeeeeeee', $i),
            'xp' => 100,
        ]);
    }

    Artisan::call('gamification:bootstrap-ledger', ['--limit' => 2]);

    Http::assertSent(fn ($r) => count($r['entries']) === 2);
});

it('aborts on py-service 5xx so ops can investigate', function () {
    Http::fake([
        'gamification.test/*' => Http::response(['detail' => 'server down'], 500),
    ]);
    User::factory()->create([
        'pandora_user_uuid' => 'ffffffff-1111-1111-1111-ffffffffffff',
        'xp' => 500,
    ]);

    expect(fn () => Artisan::call('gamification:bootstrap-ledger'))
        ->toThrow(\RuntimeException::class);
});

it('reports new and skipped counts in summary line', function () {
    Http::fake([
        'gamification.test/*' => Http::response([
            'results' => [],
            'new_bootstraps' => 2,
            'skipped' => 1,
            'total_in_request' => 3,
        ], 200),
    ]);
    foreach (range(1, 3) as $i) {
        User::factory()->create([
            'pandora_user_uuid' => sprintf('99999999-%04d-9999-9999-999999999999', $i),
            'xp' => 100,
        ]);
    }

    Artisan::call('gamification:bootstrap-ledger');
    $output = Artisan::output();

    expect($output)->toContain('new=2');
    expect($output)->toContain('skipped=1');
    expect($output)->toContain('sent=3');
});
