<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refills shield when due', function () {
    $user = User::factory()->create([
        'streak_shields' => 0, 'shield_last_refill' => null,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/shield/refill')
        ->assertOk()
        ->assertJsonPath('shields', 1)
        ->assertJsonPath('refilled', true);
});

it('uses a shield', function () {
    $user = User::factory()->create(['streak_shields' => 1]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/shield/use')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('shields_remaining', 0);
});

it('rejects use when no shields', function () {
    $user = User::factory()->create(['streak_shields' => 0]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/shield/use')
        ->assertOk()
        ->assertJsonPath('ok', false);
});
