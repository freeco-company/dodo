<?php

use App\Models\User;
use App\Services\SeasonalContentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * SPEC-seasonal-outfit-cards Phase 1 — `/api/cards/completion` contract +
 * SeasonalContentService unit checks.
 */

it('completion returns category breakdown with totals + percent', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'aaaa4444-4444-4444-4444-444444444444',
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/cards/completion')
        ->assertOk();

    expect($resp->json('completion.total'))->toBeInt();
    expect($resp->json('completion.collected'))->toBeInt();
    expect($resp->json('completion.percent'))->toBeInt();
    expect($resp->json('completion.categories'))->toBeArray();
    // Fresh user has zero collected
    expect($resp->json('completion.collected'))->toBe(0);
});

it('completion includes seasonal windows', function () {
    $user = User::factory()->create();

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/cards/completion')
        ->assertOk();

    expect($resp->json('seasonal_active'))->toBeArray();
    expect($resp->json('seasonal_upcoming'))->toBeArray();
});

it('completion endpoint rejects unauthenticated requests', function () {
    $this->getJson('/api/cards/completion')->assertStatus(401);
});

// === Unit tests for SeasonalContentService ===

it('seasonal service materializes a wrap-around winter window', function () {
    $svc = app(SeasonalContentService::class);
    // Mock now to mid-December — winter window 11-07 → 01-06 should be active
    $now = CarbonImmutable::create(2026, 12, 15, 12, 0, 0, 'Asia/Taipei');
    $active = $svc->activeAt($now);

    $winterActive = collect($active)->firstWhere('key', 'winter');
    expect($winterActive)->not->toBeNull();
    expect($winterActive['days_remaining'])->toBeGreaterThanOrEqual(0);
});

it('seasonal service materializes a January window inside the wrap-around', function () {
    $svc = app(SeasonalContentService::class);
    $now = CarbonImmutable::create(2027, 1, 3, 9, 0, 0, 'Asia/Taipei');
    $active = $svc->activeAt($now);

    $winterActive = collect($active)->firstWhere('key', 'winter');
    expect($winterActive)->not->toBeNull();
});

it('seasonal service finds an upcoming window when nothing is active', function () {
    $svc = app(SeasonalContentService::class);
    // 2026-04-10 — between spring (ends 04-04) and summer (starts 05-05)
    $now = CarbonImmutable::create(2026, 4, 10, 12, 0, 0, 'Asia/Taipei');
    $upcoming = $svc->upcomingAt($now);

    expect($upcoming)->not->toBeEmpty();
    // Closest upcoming should be summer (05-05) — 25 days away
    expect($upcoming[0]['key'])->toBe('summer');
});
