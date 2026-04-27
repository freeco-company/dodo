<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('returns stamina state', function () {
    $user = User::factory()->create(['current_streak' => 0]);
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/cards/stamina')
        ->assertOk()
        ->assertJsonPath('base', 3)
        ->assertJsonPath('used', 0);
});

it('draws a real card from the seeded JSON deck', function () {
    Artisan::call('db:seed', ['--force' => true]);

    $user = User::factory()->create(['current_streak' => 0]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/draw')
        ->assertOk()
        ->assertJsonStructure([
            'play_id',
            'id',
            'type',
            'rarity',
            'question',
            'choices',
            'is_new',
            'stamina',
        ]);

    $payload = $response->json();
    // First draw for a brand-new user must be flagged as new content.
    expect($payload['is_new'])->toBeTrue();
    expect(count($payload['choices']))->toBeGreaterThan(0);
    // Choices must NOT leak the `correct` flag to the client.
    foreach ($payload['choices'] as $choice) {
        expect($choice)->not->toHaveKey('correct');
    }
});

it('lists empty collection for new user', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/cards/collection')
        ->assertOk()
        ->assertJsonPath('total', 0);
});
