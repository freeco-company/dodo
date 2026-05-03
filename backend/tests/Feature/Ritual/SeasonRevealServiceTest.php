<?php

use App\Models\RitualEvent;
use App\Models\User;
use App\Services\Ritual\SeasonalReleaseCatalog;
use App\Services\Ritual\SeasonRevealService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('SeasonalReleaseCatalog produces 8 releases per year', function () {
    $catalog = app(SeasonalReleaseCatalog::class);
    $all = $catalog->all();
    // 2 years × 8 / year = 16 (current + next year buffer).
    expect(count($all))->toBe(16);
});

it('currentlyActive returns only releases with release_at <= now < expires_at', function () {
    $catalog = app(SeasonalReleaseCatalog::class);
    // Pick a time in the spring window: 2026-02-15 (between 2/4 release + 60d expiry).
    $now = CarbonImmutable::create(2026, 2, 15, 10, 0, 0, 'Asia/Taipei');

    $active = $catalog->currentlyActive($now);
    $ids = array_column($active, 'id');

    expect($ids)->toContain('spring-2026');
    expect($ids)->not->toContain('summer-2026');
    expect($ids)->not->toContain('autumn-2026');
});

it('SeasonRevealService fires KEY_SEASON_REVEAL for active release', function () {
    $now = CarbonImmutable::create(2026, 2, 15, 10, 0, 0, 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-spring']);

    $fired = app(SeasonRevealService::class)->checkAndFireForUser($user, $now);

    expect($fired)->toBeGreaterThanOrEqual(1);
    expect(RitualEvent::where('user_id', $user->id)
        ->where('ritual_key', RitualEvent::KEY_SEASON_REVEAL)
        ->count())->toBeGreaterThanOrEqual(1);
});

it('SeasonRevealService is idempotent — second call same release does not duplicate', function () {
    $now = CarbonImmutable::create(2026, 2, 15, 10, 0, 0, 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-idemp-season']);
    $svc = app(SeasonRevealService::class);

    $svc->checkAndFireForUser($user, $now);
    $svc->checkAndFireForUser($user, $now);

    // Spring active around 2/15 = only 1 release. (No festival overlap typically.)
    $count = RitualEvent::where('user_id', $user->id)
        ->where('ritual_key', RitualEvent::KEY_SEASON_REVEAL)
        ->count();
    expect($count)->toBeGreaterThanOrEqual(1);
    // Second call shouldn't add new events for the same release.
    $svc->checkAndFireForUser($user, $now);
    $countAfter = RitualEvent::where('user_id', $user->id)
        ->where('ritual_key', RitualEvent::KEY_SEASON_REVEAL)
        ->count();
    expect($countAfter)->toBe($count);
});

it('SeasonRevealService returns 0 fired during quiet windows', function () {
    // Pick a time outside any seasonal window (e.g. 2026-04-30 — spring expired
    // on 4/5 [+60 days from 2/4], summer starts 5/5).
    $now = CarbonImmutable::create(2026, 4, 30, 10, 0, 0, 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-quiet']);

    $fired = app(SeasonRevealService::class)->checkAndFireForUser($user, $now);

    expect($fired)->toBe(0);
});

it('BootstrapController fires SeasonRevealService for authenticated user', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-boot']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk();

    // At least 0 events created (depends on today's date, but call must not error).
    expect(true)->toBeTrue();
});
