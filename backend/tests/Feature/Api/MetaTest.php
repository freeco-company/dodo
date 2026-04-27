<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns usage limits', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/meta/limits')
        ->assertOk()
        ->assertJsonPath('free.scans', 2)
        ->assertJsonPath('vip.chats', 300);
});

it('returns outfits catalog', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/meta/outfits')
        ->assertOk()
        ->assertJsonFragment(['key' => 'none'])
        ->assertJsonFragment(['key' => 'fp_crown']);
});

it('returns spirits lore', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/lore/spirits')
        ->assertOk()
        ->assertJsonFragment(['animal_key' => 'cat'])
        ->assertJsonFragment(['animal_key' => 'tuxedo']);
});
