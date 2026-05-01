<?php

/**
 * /api/outfits — fp_crown / fp_chef 從 membership_tier=fp_lifetime 改為 is_franchisee gate。
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('locks fp_crown and fp_chef for non-franchisee', function () {
    $user = User::factory()->create(['is_franchisee' => false, 'level' => 30, 'longest_streak' => 30]);
    $body = $this->actingAs($user, 'sanctum')->getJson('/api/outfits')->assertOk()->json();

    $byKey = collect($body['outfits'] ?? $body)->keyBy('key');
    expect($byKey['fp_crown']['unlocked'])->toBeFalse();
    expect($byKey['fp_chef']['unlocked'])->toBeFalse();
});

it('unlocks fp_crown and fp_chef for franchisee', function () {
    $user = User::factory()->create(['is_franchisee' => true]);
    $body = $this->actingAs($user, 'sanctum')->getJson('/api/outfits')->assertOk()->json();

    $byKey = collect($body['outfits'] ?? $body)->keyBy('key');
    expect($byKey['fp_crown']['unlocked'])->toBeTrue();
    expect($byKey['fp_chef']['unlocked'])->toBeTrue();
});

it('blocks equipping fp outfits for non-franchisee', function () {
    $user = User::factory()->create(['is_franchisee' => false]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/outfits/equip', ['outfit_key' => 'fp_crown'])
        ->assertStatus(403);
});

it('allows equipping fp outfits for franchisee', function () {
    $user = User::factory()->create(['is_franchisee' => true]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/outfits/equip', ['outfit_key' => 'fp_crown'])
        ->assertOk();
});
