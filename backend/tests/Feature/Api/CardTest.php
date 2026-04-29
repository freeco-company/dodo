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
            'category',
            'rarity',
            'emoji',
            'question',
            'hint',
            'choices',
            'is_new',
            'stamina' => ['used', 'max', 'remaining'],
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

it('lists empty collection for new user with frontend flat shape', function () {
    Artisan::call('db:seed', ['--force' => true]);
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/cards/collection')
        ->assertOk()
        ->assertJsonStructure([
            'collected',
            'total',
            'by_rarity' => [
                'common' => ['collected', 'total'],
                'rare' => ['collected', 'total'],
                'legendary' => ['collected', 'total'],
            ],
            'event_total',
            'event_collected',
            'collected_cards',
            'locked_cards',
        ])
        ->assertJsonPath('collected', 0);
});

it('returns answer with full frontend contract', function () {
    Artisan::call('db:seed', ['--force' => true]);
    $user = User::factory()->create();
    $draw = $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/draw')
        ->assertOk()
        ->json();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/answer', ['play_id' => $draw['play_id'], 'choice_idx' => 0])
        ->assertOk()
        ->assertJsonStructure([
            'card_id', 'card_type', 'chosen_idx', 'correct',
            'reveal_correct_idx',
            'feedback', 'explain',
            'first_solve', 'combo_bonus_triggered', 'combo_count',
            'xp_gained', 'xp_breakdown',
            'leveled_up', 'level_after', 'new_achievements',
            'stamina',
        ]);
});
