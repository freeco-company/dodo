<?php

use App\Models\FastingSession;
use App\Models\RitualEvent;
use App\Models\User;
use App\Services\Gamification\OutfitMirror;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('OutfitMirror fires KEY_OUTFIT_UNLOCK_FULLSCREEN for rare outfits', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-rare']);
    $mirror = app(OutfitMirror::class);

    $added = $mirror->applyUnlocked('u-rare', [
        'codes' => ['winter_scarf'],
        'awarded_via' => 'streak_30',
    ]);

    expect($added)->toBe(1);
    expect(RitualEvent::where('user_id', $user->id)
        ->where('ritual_key', RitualEvent::KEY_OUTFIT_UNLOCK_FULLSCREEN)
        ->count())->toBe(1);
});

it('OutfitMirror does NOT fire ritual for ordinary outfit unlocks', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-ord']);
    $mirror = app(OutfitMirror::class);

    $mirror->applyUnlocked('u-ord', [
        'codes' => ['daily_quest_hat'],
        'awarded_via' => 'quest',
    ]);

    expect(RitualEvent::where('user_id', $user->id)->count())->toBe(0);
});

it('OutfitMirror is idempotent — re-firing same outfit does not duplicate ritual', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-idemp']);
    $mirror = app(OutfitMirror::class);

    $mirror->applyUnlocked('u-idemp', ['codes' => ['winter_scarf']]);
    $mirror->applyUnlocked('u-idemp', ['codes' => ['winter_scarf']]);

    expect(RitualEvent::where('user_id', $user->id)
        ->where('ritual_key', RitualEvent::KEY_OUTFIT_UNLOCK_FULLSCREEN)
        ->count())->toBe(1);
});

it('OutfitMirror separates fullscreen rituals when multiple rare outfits unlock', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-multi']);
    $mirror = app(OutfitMirror::class);

    $mirror->applyUnlocked('u-multi', ['codes' => ['winter_scarf', 'sakura_kimono']]);

    expect(RitualEvent::where('user_id', $user->id)->count())->toBe(2);
});
