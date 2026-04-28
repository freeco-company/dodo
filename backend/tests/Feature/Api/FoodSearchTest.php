<?php

use App\Models\Food;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Minimal fixtures — keeps tests fast and avoids depending on the full
    // food seed which can shift counts when content team edits the JSON.
    Food::create([
        'name_zh' => '雞胸肉',
        'name_en' => 'chicken breast',
        'category' => 'protein',
        'element' => 'protein',
        'serving_description' => '一份',
        'serving_weight_g' => 100,
        'calories' => 165,
        'protein_g' => 31,
        'carbs_g' => 0,
        'fat_g' => 3.6,
        'aliases' => ['雞胸', 'chicken'],
        'verified' => true,
    ]);
    Food::create([
        'name_zh' => '雞腿便當',
        'name_en' => 'chicken leg bento',
        'category' => 'meal',
        'element' => 'protein',
        'serving_description' => '一份',
        'serving_weight_g' => 600,
        'calories' => 850,
        'protein_g' => 35,
        'carbs_g' => 95,
        'fat_g' => 32,
        'aliases' => [],
        'verified' => false,
    ]);
    Food::create([
        'name_zh' => '便當',
        'name_en' => null,
        'category' => 'meal',
        'element' => 'neutral',
        'serving_description' => '一份',
        'serving_weight_g' => 500,
        'calories' => 700,
        'protein_g' => 25,
        'carbs_g' => 90,
        'fat_g' => 25,
        'aliases' => ['餐盒'],
        'verified' => false,
    ]);
});

it('requires sanctum authentication', function () {
    $this->getJson('/api/foods/search?q='.urlencode('雞'))
        ->assertStatus(401);
});

it('returns the exact match first then like-fallback rows', function () {
    $user = User::factory()->create(['pandora_user_uuid' => '00000000-0000-0000-0000-000000000001']);

    // Pre-encode CJK so the symfony test client does not mangle bytes.
    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/foods/search?q='.urlencode('雞胸肉'));

    $response->assertOk();
    $items = $response->json('data');
    expect($items)->toBeArray();
    expect($items[0]['name_zh'])->toBe('雞胸肉');
    expect($items[0])->toHaveKeys(['id', 'name_zh', 'calories', 'protein_g', 'aliases']);
});

it('matches via alias hit when no direct name match exists', function () {
    $user = User::factory()->create(['pandora_user_uuid' => '00000000-0000-0000-0000-000000000002']);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/foods/search?q='.urlencode('餐盒'));

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name_zh')->all();
    expect($names)->toContain('便當');
});

it('returns empty data on blank query without 422', function () {
    $user = User::factory()->create(['pandora_user_uuid' => '00000000-0000-0000-0000-000000000003']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/foods/search?q=')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('returns empty data when nothing matches', function () {
    $user = User::factory()->create(['pandora_user_uuid' => '00000000-0000-0000-0000-000000000004']);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/foods/search?q='.urlencode('完全不存在的食物XYZ'))
        ->assertOk()
        ->assertJsonPath('data', []);
});
