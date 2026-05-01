<?php

/**
 * Catalog parity guard — pandora-meal `AchievementController::CATALOG`
 * MUST be a subset of py-service `ACHIEVEMENT_CATALOG` keys.
 *
 * Background (PR #99 regression): the curated grey-card catalog had its own
 * unprefixed keys (`first_meal`, `streak_7`) while py-service emits namespaced
 * codes (`meal.first_meal`, `meal.streak_7`). Result: curated cards never
 * unlocked, real awards orphan-appended with empty descriptions.
 *
 * py-service is a separate repo so we hardcode the expected list here. When
 * py-service catalog grows, update both this list and the controller catalog
 * in the same PR.
 *
 * Source of truth: py-service/app/gamification/catalog.py §5.2 ACHIEVEMENT_CATALOG.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const PY_SERVICE_ACHIEVEMENT_CODES = [
    // meal
    'meal.first_meal',
    'meal.streak_7',
    'meal.streak_30',
    'meal.foodie_10',
    // jerosse
    'jerosse.first_browse',
    'jerosse.first_order',
    'jerosse.spend_10k',
    // group
    'group.multi_app_explorer',
    'group.full_constellation',
];

it('catalog keys are a subset of py-service codes (no orphan curated cards)', function () {
    $user = User::factory()->create();
    $body = $this->actingAs($user, 'sanctum')
        ->getJson('/api/achievements')
        ->assertOk()
        ->json();

    $keys = array_column($body['achievements'], 'key');
    foreach ($keys as $key) {
        expect(in_array($key, PY_SERVICE_ACHIEVEMENT_CODES, true))
            ->toBeTrue("controller catalog key '{$key}' is not in py-service ACHIEVEMENT_CATALOG");
    }
});

it('catalog keys are all namespaced (contain a dot)', function () {
    $user = User::factory()->create();
    $body = $this->actingAs($user, 'sanctum')
        ->getJson('/api/achievements')
        ->assertOk()
        ->json();

    foreach (array_column($body['achievements'], 'key') as $key) {
        expect($key)->toContain('.');
    }
});

it('every py-service code has a curated catalog entry (no grey-forever cards)', function () {
    $user = User::factory()->create();
    $body = $this->actingAs($user, 'sanctum')
        ->getJson('/api/achievements')
        ->assertOk()
        ->json();

    $keys = array_column($body['achievements'], 'key');
    foreach (PY_SERVICE_ACHIEVEMENT_CODES as $code) {
        expect(in_array($code, $keys, true))
            ->toBeTrue("py-service code '{$code}' missing from controller catalog");
    }
});
