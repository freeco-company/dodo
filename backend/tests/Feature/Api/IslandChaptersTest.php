<?php

use App\Models\StoreVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 7 chapters with story metadata', function () {
    $user = User::factory()->create(['level' => 1]);
    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/island/chapters')
        ->assertOk();

    expect($resp->json('chapters'))->toHaveCount(7);
    expect($resp->json('chapters.0.key'))->toBe('awakening');
    expect($resp->json('chapters.6.key'))->toBe('fp_temple');
    expect($resp->json('chapters.0'))->toHaveKeys(['name', 'intro', 'subtitle', 'theme', 'boss', 'reward', 'min_level', 'is_current']);
});

it('marks chapter 1 unlocked at level 1, others locked', function () {
    $user = User::factory()->create(['level' => 1]);
    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/island/chapters');

    expect($resp->json('chapters.0.unlocked'))->toBeTrue();
    expect($resp->json('chapters.0.is_current'))->toBeTrue();
    expect($resp->json('chapters.1.unlocked'))->toBeFalse();
    expect($resp->json('chapters.6.unlocked'))->toBeFalse();
});

it('reflects level-based unlock for chapter 4 (lv 8)', function () {
    $user = User::factory()->create(['level' => 8]);
    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/island/chapters');

    expect($resp->json('chapters.3.key'))->toBe('beverage_maze');
    expect($resp->json('chapters.3.unlocked'))->toBeTrue();
    expect($resp->json('chapters.4.unlocked'))->toBeFalse();
});

it('counts chapter store visits and computes progress', function () {
    $user = User::factory()->create(['level' => 5]);

    StoreVisit::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'store_key' => 'mcdonalds',
        'visit_count' => 3,
        'last_visited_at' => now(),
    ]);

    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/island/chapters');

    $ch3 = collect($resp->json('chapters'))->firstWhere('key', 'fast_food_trial');
    expect($ch3['stores_visited'])->toBe(1);
    expect($ch3['stores_total'])->toBe(2);
    expect($ch3['store_progress_percent'])->toBe(50);
    expect($ch3['boss_completed'])->toBeFalse();
});

it('marks chapter completed when all stores visited', function () {
    $user = User::factory()->create(['level' => 1]);
    StoreVisit::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'store_key' => 'familymart',
        'visit_count' => 1,
        'last_visited_at' => now(),
    ]);

    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/island/chapters');

    expect($resp->json('chapters.0.boss_completed'))->toBeTrue();
    expect($resp->json('chapters.0.status'))->toBe('completed');
});

it('reports next_unlock hint for locked user', function () {
    $user = User::factory()->create(['level' => 2]);
    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/island/chapters');

    expect($resp->json('next_unlock.chapter_key'))->toBe('smart_convenience');
    expect($resp->json('next_unlock.levels_away'))->toBe(1);
});

it('chapters endpoint requires auth', function () {
    $this->getJson('/api/island/chapters')->assertStatus(401);
});
