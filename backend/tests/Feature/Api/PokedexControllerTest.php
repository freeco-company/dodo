<?php

/**
 * /api/pokedex 圖鑑 — index (full list + locked greying) + show (food detail).
 *
 * Coverage:
 *   - index returns ALL foods, locked ones marked unlocked=false
 *   - non-franchisee never sees FP-branded foods
 *   - franchisee sees everything (locked + unlocked + FP)
 *   - show 404 on unknown food, 404 on FP food for non-franchisee
 *   - show returns hint stub for known-but-not-yet-discovered food
 *   - show returns full payload + unlocked_via card history when card_play set
 */

use App\Models\CardPlay;
use App\Models\FoodDiscovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    DB::table('food_database')->insert([
        ['id' => 9001, 'name_zh' => '蘋果', 'category' => 'fruit', 'brand' => null, 'element' => 'energy'],
        ['id' => 9002, 'name_zh' => '雞胸', 'category' => 'protein', 'brand' => null, 'element' => 'energy'],
        ['id' => 9003, 'name_zh' => 'FP 厚焙奶茶', 'category' => 'drink', 'brand' => '婕樂纖', 'element' => 'energy'],
    ]);
});

it('returns all foods with unlocked flag for non-franchisee, hides FP brand', function () {
    $user = User::factory()->create(['is_franchisee' => false]);
    FoodDiscovery::create([
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'food_id' => 9001,
        'first_seen_at' => now(),
        'times_eaten' => 1,
    ]);

    $body = $this->actingAs($user, 'sanctum')->getJson('/api/pokedex')->assertOk()->json();

    $ids = collect($body['entries'])->pluck('food_id')->all();
    expect($ids)->toContain(9001, 9002);
    expect($ids)->not->toContain(9003);

    $apple = collect($body['entries'])->firstWhere('food_id', 9001);
    $chicken = collect($body['entries'])->firstWhere('food_id', 9002);
    expect($apple['unlocked'])->toBeTrue();
    expect($chicken['unlocked'])->toBeFalse();
    expect($body['unlocked_count'])->toBe(1);
});

it('shows FP-branded foods to franchisee', function () {
    $user = User::factory()->create(['is_franchisee' => true]);
    $body = $this->actingAs($user, 'sanctum')->getJson('/api/pokedex')->assertOk()->json();
    $ids = collect($body['entries'])->pluck('food_id')->all();
    expect($ids)->toContain(9003);
});

it('returns 404 for FP food when non-franchisee hits show', function () {
    $user = User::factory()->create(['is_franchisee' => false]);
    $this->actingAs($user, 'sanctum')->getJson('/api/pokedex/9003')->assertNotFound();
});

it('returns 404 for unknown food id', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')->getJson('/api/pokedex/99999')->assertNotFound();
});

it('returns hint stub for known but not yet discovered food', function () {
    $user = User::factory()->create();
    $body = $this->actingAs($user, 'sanctum')->getJson('/api/pokedex/9002')->assertOk()->json();
    expect($body['unlocked'])->toBeFalse();
    expect($body['food_id'])->toBe(9002);
    expect($body)->toHaveKey('hint');
});

it('returns full payload with unlocked_via card history when card_play linked', function () {
    $user = User::factory()->create();
    $play = CardPlay::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => now()->toDateString(),
        'card_id' => 'demo-card-1',
        'card_type' => 'q_card',
        'rarity' => 'common',
        'choice_idx' => 0,
        'correct' => true,
        'answered_at' => now(),
    ]);

    FoodDiscovery::create([
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'food_id' => 9001,
        'first_seen_at' => now(),
        'times_eaten' => 3,
        'best_score' => 88,
        'is_shiny' => true,
        'unlocked_via_card_play_id' => $play->id,
    ]);

    $body = $this->actingAs($user, 'sanctum')->getJson('/api/pokedex/9001')->assertOk()->json();
    expect($body['unlocked'])->toBeTrue();
    expect($body['times_eaten'])->toBe(3);
    expect($body['is_shiny'])->toBeTrue();
    expect($body)->toHaveKey('unlocked_via');
    expect($body['unlocked_via']['play_id'])->toBe($play->id);
    expect($body['unlocked_via']['card_id'])->toBe('demo-card-1');
});

it('rejects unauthenticated requests', function () {
    $this->getJson('/api/pokedex')->assertUnauthorized();
    $this->getJson('/api/pokedex/9001')->assertUnauthorized();
});
