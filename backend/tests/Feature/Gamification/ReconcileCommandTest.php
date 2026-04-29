<?php

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_gamification.base_url', 'https://gamification.test');
    config()->set('services.pandora_gamification.shared_secret', 'test-secret');
});

function reconcileSyncResponse(array $overrides = []): array
{
    return array_replace_recursive([
        'pandora_user_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
        'progression' => [
            'pandora_user_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
            'total_xp' => 500,
            'group_level' => 7,
            'level_name_zh' => '前進期',
            'level_name_en' => 'Advancing',
            'level_anchor_xp' => 500,
            'xp_to_next_level' => 100,
        ],
        'achievements' => [
            ['code' => 'meal.streak_7', 'tier' => 'bronze', 'awarded_at' => '2026-04-29T08:00:00Z', 'source_app' => 'meal'],
        ],
        'outfits' => [
            ['code' => 'scarf', 'awarded_at' => '2026-04-29T08:00:00Z', 'awarded_via' => 'level_up'],
        ],
    ], $overrides);
}

it('errors out when env not configured', function () {
    config()->set('services.pandora_gamification.base_url', '');

    $exit = Artisan::call('gamification:reconcile', ['uuid' => 'x']);

    expect($exit)->toBe(1);
});

it('errors out on non-2xx sync response', function () {
    Http::fake([
        'gamification.test/*' => Http::response(['detail' => 'unauthorized'], 401),
    ]);

    $exit = Artisan::call('gamification:reconcile', ['uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001']);

    expect($exit)->toBe(1);
});

it('--dry-run reads sync but does NOT write local mirror', function () {
    Http::fake([
        'gamification.test/*' => Http::response(reconcileSyncResponse(), 200),
    ]);
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
        'level' => 1,
        'xp' => 0,
        'outfits_owned' => ['none'],
    ]);

    Artisan::call('gamification:reconcile', [
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
        '--dry-run' => true,
    ]);

    $fresh = $user->fresh();
    expect($fresh->level)->toBe(1)
        ->and($fresh->xp)->toBe(0)
        ->and($fresh->outfits_owned)->toBe(['none']);
    expect(Achievement::count())->toBe(0);
});

it('replays snapshot through all three mirrors', function () {
    Http::fake([
        'gamification.test/*' => Http::response(reconcileSyncResponse(), 200),
    ]);
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
        'level' => 1,
        'xp' => 0,
        'outfits_owned' => ['none'],
    ]);

    $exit = Artisan::call('gamification:reconcile', [
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
    ]);

    expect($exit)->toBe(0);
    $fresh = $user->fresh();
    expect($fresh->level)->toBe(7)
        ->and($fresh->xp)->toBe(500)
        ->and($fresh->outfits_owned)->toBe(['none', 'scarf']);

    expect(Achievement::where('user_id', $user->id)->count())->toBe(1);
    $row = Achievement::where('user_id', $user->id)->first();
    expect($row->achievement_key)->toBe('meal.streak_7');
});

it('hits the sync endpoint with X-Internal-Secret header', function () {
    Http::fake([
        'gamification.test/*' => Http::response(reconcileSyncResponse(), 200),
    ]);
    User::factory()->create([
        'pandora_user_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
    ]);

    Artisan::call('gamification:reconcile', [
        'uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://gamification.test/api/v1/internal/gamification/users/aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001/sync'
            && $request->method() === 'GET'
            && $request->hasHeader('X-Internal-Secret', 'test-secret');
    });
});

it('is idempotent — running twice does not duplicate writes', function () {
    Http::fake([
        'gamification.test/*' => Http::response(reconcileSyncResponse(), 200),
    ]);
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
        'level' => 1,
        'xp' => 0,
        'outfits_owned' => ['none'],
    ]);

    Artisan::call('gamification:reconcile', ['uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001']);
    Artisan::call('gamification:reconcile', ['uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001']);

    expect(Achievement::where('user_id', $user->id)->count())->toBe(1);
    expect($user->fresh()->outfits_owned)->toBe(['none', 'scarf']);
});
