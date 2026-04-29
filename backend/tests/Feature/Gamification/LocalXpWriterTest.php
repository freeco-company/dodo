<?php

use App\Models\User;
use App\Services\Gamification\LocalXpWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.pandora_gamification.local_xp_writes_enabled', true);
});

it('applies xp delta and bumps level when flag enabled', function () {
    $u = User::factory()->create(['xp' => 50, 'level' => 1]);
    $writer = app(LocalXpWriter::class);

    [$before, $after, $applied] = $writer->apply($u, 100);

    expect($applied)->toBeTrue()
        ->and($before)->toBe(1)
        ->and($u->fresh()->xp)->toBe(150)
        ->and($u->fresh()->level)->toBeGreaterThanOrEqual(1);
});

it('does not write the user row when flag disabled (Phase B.3 cutover state)', function () {
    config()->set('services.pandora_gamification.local_xp_writes_enabled', false);
    $u = User::factory()->create(['xp' => 50, 'level' => 1]);

    [, , $applied] = app(LocalXpWriter::class)->apply($u, 100);

    expect($applied)->toBeFalse()
        ->and($u->fresh()->xp)->toBe(50)
        ->and($u->fresh()->level)->toBe(1);
});

it('still forecasts level_after when flag disabled (frontend optimistic UI)', function () {
    config()->set('services.pandora_gamification.local_xp_writes_enabled', false);
    // Need enough xp gain to actually cross a level boundary
    $u = User::factory()->create(['xp' => 0, 'level' => 1]);

    [$before, $after, $applied] = app(LocalXpWriter::class)->apply($u, 100);

    expect($applied)->toBeFalse()
        ->and($before)->toBe(1)
        ->and($after)->toBeGreaterThanOrEqual(1)
        // The user row stayed at 50/1, but `after` reflects the post-webhook
        // forecast so the API response can carry `leveled_up: true` instantly.
        ->and($u->fresh()->xp)->toBe(0);
});

it('returns early on zero or negative delta without writing', function () {
    $u = User::factory()->create(['xp' => 50, 'level' => 1]);

    [, , $applied] = app(LocalXpWriter::class)->apply($u, 0);
    expect($applied)->toBeFalse();
    expect($u->fresh()->xp)->toBe(50);

    [, , $applied2] = app(LocalXpWriter::class)->apply($u, -10);
    expect($applied2)->toBeFalse();
    expect($u->fresh()->xp)->toBe(50);
});

it('enabled() reflects the config flag', function () {
    $w = app(LocalXpWriter::class);
    config()->set('services.pandora_gamification.local_xp_writes_enabled', false);
    expect($w->enabled())->toBeFalse();
    config()->set('services.pandora_gamification.local_xp_writes_enabled', true);
    expect($w->enabled())->toBeTrue();
});
