<?php

use App\Models\User;
use App\Services\Dodo\Streak\DailyLoginStreakService;
use App\Services\Dodo\Streak\StreakMilestoneRewardService;
use App\Services\Gamification\GamificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 4, 9, 0, 0, 'Asia/Taipei'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('milestone 1 unlocks initial card with no outfit', function () {
    $user = User::factory()->create();
    $svc = app(StreakMilestoneRewardService::class);

    $r = $svc->unlockForMilestone($user, 1);

    expect($r['outfit_unlocked'])->toBeNull()
        ->and($r['cards_unlocked'])->toHaveCount(1)
        ->and($r['cards_unlocked'][0]['code'])->toBe('streak_1')
        ->and($r['xp_bonus'])->toBe(0);
});

it('milestone 3 unlocks scarf outfit + card', function () {
    $user = User::factory()->create(['outfits_owned' => ['none']]);
    $svc = app(StreakMilestoneRewardService::class);

    $r = $svc->unlockForMilestone($user, 3);

    expect($r['outfit_unlocked'])->toBe('scarf')
        ->and($r['cards_unlocked'][0]['code'])->toBe('streak_3');

    $user->refresh();
    expect($user->outfits_owned)->toContain('scarf');
});

it('milestone 7 unlocks straw_hat', function () {
    $user = User::factory()->create(['outfits_owned' => ['none']]);
    $r = app(StreakMilestoneRewardService::class)->unlockForMilestone($user, 7);

    expect($r['outfit_unlocked'])->toBe('straw_hat');
    $user->refresh();
    expect($user->outfits_owned)->toContain('straw_hat');
});

it('milestone 14 unlocks sakura', function () {
    $user = User::factory()->create(['outfits_owned' => ['none']]);
    $r = app(StreakMilestoneRewardService::class)->unlockForMilestone($user, 14);

    expect($r['outfit_unlocked'])->toBe('sakura');
});

it('milestone 21 unlocks witch_hat + 50 XP bonus', function () {
    $user = User::factory()->create(['outfits_owned' => ['none'], 'xp' => 0, 'level' => 1]);
    $r = app(StreakMilestoneRewardService::class)->unlockForMilestone($user, 21);

    expect($r['outfit_unlocked'])->toBe('witch_hat')
        ->and($r['xp_bonus'])->toBe(50)
        ->and($r['level_after'])->not->toBeNull();
});

it('milestone 30 unlocks winter_scarf + 100 XP bonus', function () {
    $user = User::factory()->create(['outfits_owned' => ['none'], 'xp' => 0, 'level' => 1]);
    $r = app(StreakMilestoneRewardService::class)->unlockForMilestone($user, 30);

    expect($r['outfit_unlocked'])->toBe('winter_scarf')
        ->and($r['xp_bonus'])->toBe(100);
});

it('milestone 60 unlocks card only (no outfit)', function () {
    $user = User::factory()->create(['outfits_owned' => ['none']]);
    $r = app(StreakMilestoneRewardService::class)->unlockForMilestone($user, 60);

    expect($r['outfit_unlocked'])->toBeNull()
        ->and($r['cards_unlocked'][0]['code'])->toBe('streak_60');
});

it('milestone 100 unlocks angel_wings', function () {
    $user = User::factory()->create(['outfits_owned' => ['none']]);
    $r = app(StreakMilestoneRewardService::class)->unlockForMilestone($user, 100);

    expect($r['outfit_unlocked'])->toBe('angel_wings');
});

it('non-milestone returns empty unlocks', function () {
    $user = User::factory()->create();
    $r = app(StreakMilestoneRewardService::class)->unlockForMilestone($user, 5);

    expect($r['outfit_unlocked'])->toBeNull()
        ->and($r['cards_unlocked'])->toBe([])
        ->and($r['xp_bonus'])->toBe(0);
});

it('outfit unlock is idempotent — already-owned returns null', function () {
    $user = User::factory()->create(['outfits_owned' => ['none', 'scarf']]);
    $r = app(StreakMilestoneRewardService::class)->unlockForMilestone($user, 3);

    // Already owned → reported as unlocked=null so the toast doesn't repeat.
    expect($r['outfit_unlocked'])->toBeNull();

    $user->refresh();
    // Still in the owned set, just not duplicated.
    $owned = (array) $user->outfits_owned;
    expect(array_count_values(array_filter($owned, fn ($v) => $v === 'scarf'))['scarf'] ?? 0)->toBe(1);
});

it('recordLogin returns unlocks payload at milestone', function () {
    $user = User::factory()->create(['outfits_owned' => ['none']]);
    $svc = app(DailyLoginStreakService::class);

    // Day 1 — milestone 1.
    $r = $svc->recordLogin($user);

    expect($r['is_milestone'])->toBeTrue()
        ->and($r['unlocks'])->not->toBeNull()
        ->and($r['unlocks']['cards_unlocked'][0]['code'])->toBe('streak_1');
});

it('recordLogin returns null unlocks on non-milestone day', function () {
    $user = User::factory()->create();
    $svc = app(DailyLoginStreakService::class);

    $svc->recordLogin($user); // day 1 (milestone)
    Carbon::setTestNow(Carbon::create(2026, 5, 5, 9, 0, 0, 'Asia/Taipei'));
    $r = $svc->recordLogin($user); // day 2 (non-milestone)

    expect($r['is_milestone'])->toBeFalse()
        ->and($r['unlocks'])->toBeNull();
});

it('recordLogin same-day no-op does not re-trigger unlocks', function () {
    $user = User::factory()->create(['outfits_owned' => ['none']]);
    $svc = app(DailyLoginStreakService::class);

    $svc->recordLogin($user); // day 1 milestone, unlocks scarf? no — milestone=1
    // Second call same day must return is_first_today=false AND unlocks=null.
    Carbon::setTestNow(Carbon::create(2026, 5, 4, 18, 0, 0, 'Asia/Taipei'));
    $r = $svc->recordLogin($user);

    expect($r['is_first_today'])->toBeFalse()
        ->and($r['is_milestone'])->toBeFalse()
        ->and($r['unlocks'])->toBeNull();
});

it('publish failure does not break milestone unlock', function () {
    // Stub publisher that throws — milestone unlock still completes.
    $stub = new class extends GamificationPublisher
    {
        public function publish(
            string $pandoraUserUuid,
            string $eventKind,
            string $idempotencyKey,
            array $metadata = [],
            ?\Carbon\CarbonInterface $occurredAt = null,
        ): void {
            throw new \RuntimeException('boom');
        }
    };
    app()->instance(GamificationPublisher::class, $stub);

    $user = User::factory()->create(['pandora_user_uuid' => 'milestone-uuid', 'outfits_owned' => ['none']]);

    $r = app(StreakMilestoneRewardService::class)->unlockForMilestone($user, 3);

    // Outfit still unlocked locally.
    expect($r['outfit_unlocked'])->toBe('scarf');
    $user->refresh();
    expect($user->outfits_owned)->toContain('scarf');
});
