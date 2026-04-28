<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('pets the character and gains friendship', function () {
    $user = User::factory()->create([
        'friendship' => 0, 'daily_pet_count' => 0, 'last_pet_date' => null,
        'avatar_animal' => 'cat',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/interact/pet')
        ->assertOk()
        ->assertJsonStructure(['friendship', 'pet_count', 'capped', 'message'])
        ->assertJsonPath('friendship', 2)
        ->assertJsonPath('pet_count', 1)
        ->assertJsonPath('capped', false);
});

it('caps pet at 5/day', function () {
    $user = User::factory()->create([
        'friendship' => 0, 'daily_pet_count' => 5, 'last_pet_date' => now()->toDateString(),
        'avatar_animal' => 'cat',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/interact/pet')
        ->assertOk()
        ->assertJsonPath('capped', true);
});

it('claims daily gift first time', function () {
    $user = User::factory()->create([
        'last_gift_date' => null, 'streak_shields' => 0,
        'avatar_animal' => 'cat', 'xp' => 0, 'level' => 1, 'friendship' => 0,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/interact/gift')
        ->assertOk()
        ->assertJsonStructure([
            'claimed',
            'reward' => ['kind', 'title', 'subtitle', 'emoji'],
            'next_available_in_ms',
            'next_available_at',
        ])
        ->assertJsonPath('claimed', true);
});

it('returns gift status with frontend contract', function () {
    $user = User::factory()->create(['last_gift_date' => null]);
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/interact/gift/status')
        ->assertOk()
        ->assertJsonStructure(['can_open', 'next_available_in_ms', 'next_available_at'])
        ->assertJsonPath('can_open', true);
});
