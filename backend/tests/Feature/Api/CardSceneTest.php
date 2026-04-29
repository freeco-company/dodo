<?php

use App\Models\CardPlay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('db:seed', ['--force' => true]);
});

it('scene-draw requires auth', function () {
    $this->postJson('/api/cards/scene-draw', ['card_id' => 'fm-egg'])->assertStatus(401);
});

it('scene-draw 422s when card_id missing', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '00000000-0000-0000-0000-0000000000f1',
        'current_streak' => 0,
        'level' => 5,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/scene-draw', [])
        ->assertStatus(422);
});

it('scene-draw 404s for an unknown card_id', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '00000000-0000-0000-0000-0000000000f2',
        'current_streak' => 0,
        'level' => 5,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/scene-draw', ['card_id' => 'no-such-card'])
        ->assertStatus(404);
});

it('scene-draw creates a card_play tagged to the caller (tenant isolation)', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '00000000-0000-0000-0000-0000000000f3',
        'current_streak' => 0,
        'level' => 5,
    ]);
    $other = User::factory()->create([
        'pandora_user_uuid' => '00000000-0000-0000-0000-0000000000f4',
    ]);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/scene-draw', ['card_id' => 'fm-egg'])
        ->assertOk()
        ->assertJsonStructure(['play_id', 'id', 'choices', 'stamina']);

    $playId = (int) $response->json('play_id');
    $play = CardPlay::find($playId);
    expect($play)->not->toBeNull();
    expect($play->pandora_user_uuid)->toBe($user->pandora_user_uuid);
    expect($play->user_id)->toBe($user->id);

    // Confirm the other tenant's collection cannot see this play.
    $otherCollection = $this->actingAs($other, 'sanctum')
        ->getJson('/api/cards/collection')
        ->assertOk();
    expect($otherCollection->json('collected'))->toBe(0);
});

it('scene-draw rejects a second draw of the same card on the same day', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => '00000000-0000-0000-0000-0000000000f5',
        'current_streak' => 0,
        'level' => 5,
    ]);

    // First draw — answer it so the once-per-day rule kicks in.
    $first = $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/scene-draw', ['card_id' => 'fm-egg'])
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/answer', [
            'play_id' => (int) $first->json('play_id'),
            'choice_idx' => 0,
        ])->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/scene-draw', ['card_id' => 'fm-egg'])
        ->assertStatus(409);
});
