<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns 3 daily quests for the user', function () {
    $user = User::factory()->create();
    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/quests/today')
        ->assertOk();
    $resp->assertJsonStructure(['quests', 'all_completed'])
        ->assertJsonCount(3, 'quests');
});

it('shows quests with progress fields', function () {
    $user = User::factory()->create();
    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/quests/today')->assertOk();
    $first = $resp->json('quests.0');
    expect($first)->toHaveKeys(['key', 'label', 'emoji', 'target', 'progress', 'completed', 'reward_xp', 'rarity']);
});
