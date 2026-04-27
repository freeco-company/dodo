<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('meal scan returns 503 + AI_SERVICE_DOWN', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', ['image_url' => 'https://example.com/img.jpg'])
        ->assertStatus(503)
        ->assertJsonPath('error_code', 'AI_SERVICE_DOWN');
});

it('meal text returns 503 + AI_SERVICE_DOWN', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/text', ['description' => '一個雞腿便當'])
        ->assertStatus(503)
        ->assertJsonPath('error_code', 'AI_SERVICE_DOWN');
});

it('chat message returns 503 but persists user message', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/chat/message', ['content' => '今天好餓'])
        ->assertStatus(503)
        ->assertJsonPath('error_code', 'AI_SERVICE_DOWN');

    expect(Conversation::where('user_id', $user->id)->where('role', 'user')->count())->toBe(1);
});

it('chat starters works without AI', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/chat/starters')
        ->assertOk()
        ->assertJsonStructure(['welcome', 'starters']);
});

it('chat message validates content', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/chat/message', [])
        ->assertStatus(422);
});
