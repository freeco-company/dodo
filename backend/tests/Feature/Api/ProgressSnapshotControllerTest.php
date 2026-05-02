<?php

use App\Models\ProgressSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * SPEC-progress-photo-album Phase 1 — metadata-only contract tests.
 *
 * Yearly+ tier gating is the spec's whole monetization angle (NT$2,400 hook),
 * so test it explicitly across store / timeline / destroy.
 */

it('blocks free users with 402 and paywall payload', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/progress/snapshot', [
            'taken_at' => '2026-05-03T07:00:00+08:00',
            'weight_kg' => 53.5,
        ])
        ->assertStatus(402)
        ->assertJsonPath('error_code', 'PROGRESS_TIER_LOCKED')
        ->assertJsonPath('paywall.tier_required', 'yearly');
});

it('blocks monthly subscribers with 402', function () {
    $user = User::factory()->create([
        'subscription_type' => 'app_monthly',
        'subscription_expires_at_iso' => now()->addMonth(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/progress/timeline')
        ->assertStatus(402);
});

it('allows yearly subscribers to store and read snapshots', function () {
    $user = User::factory()->create([
        'subscription_type' => 'app_yearly',
        'subscription_expires_at_iso' => now()->addYear(),
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/progress/snapshot', [
            'taken_at' => '2026-05-03T07:00:00+08:00',
            'weight_kg' => 53.5,
            'mood' => '🙂',
            'notes' => '腰圍變細了',
            'photo_ref' => 'device-local-uuid-1',
        ])
        ->assertCreated();

    expect($resp->json('weight_kg'))->toBe(53.5);
    expect($resp->json('mood'))->toBe('🙂');

    $tl = $this->actingAs($user, 'sanctum')
        ->getJson('/api/progress/timeline')
        ->assertOk();

    expect($tl->json('data'))->toHaveCount(1);
});

it('allows fp_lifetime users (treated as VIP)', function () {
    $user = User::factory()->create(['membership_tier' => 'fp_lifetime']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/progress/snapshot', [
            'taken_at' => '2026-05-03T07:00:00+08:00',
            'weight_kg' => 50.0,
        ])
        ->assertCreated();
});

it('rejects out-of-range weight values', function () {
    $user = User::factory()->create(['subscription_type' => 'app_yearly', 'subscription_expires_at_iso' => now()->addYear()]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/progress/snapshot', [
            'taken_at' => '2026-05-03T07:00:00+08:00',
            'weight_kg' => 999,
        ])
        ->assertStatus(422);
});

it('store accepts metadata without weight (notes / mood only entry)', function () {
    $user = User::factory()->create(['membership_tier' => 'fp_lifetime']);

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/progress/snapshot', [
            'taken_at' => '2026-05-03T07:00:00+08:00',
            'mood' => '✨',
        ])
        ->assertCreated();

    expect($resp->json('weight_kg'))->toBeNull();
    expect($resp->json('mood'))->toBe('✨');
});

it('destroy removes only own snapshots', function () {
    $owner = User::factory()->create(['membership_tier' => 'fp_lifetime']);
    $other = User::factory()->create(['membership_tier' => 'fp_lifetime']);
    $snap = ProgressSnapshot::create([
        'user_id' => $owner->id,
        'taken_at' => now(),
        'weight_g' => 53000,
    ]);

    $this->actingAs($other, 'sanctum')
        ->deleteJson("/api/progress/snapshot/{$snap->id}")
        ->assertStatus(404);

    $this->actingAs($owner, 'sanctum')
        ->deleteJson("/api/progress/snapshot/{$snap->id}")
        ->assertStatus(204);

    expect(ProgressSnapshot::find($snap->id))->toBeNull();
});

it('timeline filters to days window', function () {
    $user = User::factory()->create(['membership_tier' => 'fp_lifetime']);
    ProgressSnapshot::create([
        'user_id' => $user->id, 'taken_at' => now()->subDays(30), 'weight_g' => 54000,
    ]);
    ProgressSnapshot::create([
        'user_id' => $user->id, 'taken_at' => now()->subDays(120), 'weight_g' => 56000,
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/progress/timeline?days=60')
        ->assertOk();

    expect($resp->json('data'))->toHaveCount(1);
    expect($resp->json('window_days'))->toBe(60);
});

it('all 3 endpoints reject unauthenticated requests', function () {
    $this->postJson('/api/progress/snapshot', [])->assertStatus(401);
    $this->getJson('/api/progress/timeline')->assertStatus(401);
    $this->deleteJson('/api/progress/snapshot/1')->assertStatus(401);
});
