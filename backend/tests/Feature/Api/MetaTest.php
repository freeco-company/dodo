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

it('returns spirits lore — anchor v2 11-species lineup', function () {
    $user = User::factory()->create();
    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/lore/spirits')
        ->assertOk()
        ->assertJsonFragment(['animal_key' => 'rabbit'])
        ->assertJsonFragment(['animal_key' => 'cat'])
        ->assertJsonFragment(['animal_key' => 'tiger'])
        ->assertJsonFragment(['animal_key' => 'penguin'])
        ->assertJsonFragment(['animal_key' => 'bear'])
        ->assertJsonFragment(['animal_key' => 'dog'])
        ->assertJsonFragment(['animal_key' => 'fox'])
        ->assertJsonFragment(['animal_key' => 'dinosaur'])
        ->assertJsonFragment(['animal_key' => 'sheep'])
        ->assertJsonFragment(['animal_key' => 'pig'])
        ->assertJsonFragment(['animal_key' => 'robot']);

    // Legacy species must be gone
    $keys = collect($resp->json())->pluck('animal_key')->all();
    expect($keys)->toHaveCount(11);
    expect($keys)->not->toContain('hamster');
    expect($keys)->not->toContain('shiba');
    expect($keys)->not->toContain('tuxedo');
});
