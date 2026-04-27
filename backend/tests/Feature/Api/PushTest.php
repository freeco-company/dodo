<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('registers a push token', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/push/register', [
            'platform' => 'ios',
            'token' => 'dummy-apns-token-aaaaaaaa',
        ])
        ->assertOk()
        ->assertJsonStructure(['id']);
    expect(DB::table('push_tokens')->where('user_id', $user->id)->exists())->toBeTrue();
});

it('upsert: re-registering same token does not duplicate', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');
    $this->postJson('/api/push/register', ['platform' => 'ios', 'token' => 'tok-aaaaaaaaaa'])->assertOk();
    $this->postJson('/api/push/register', ['platform' => 'ios', 'token' => 'tok-aaaaaaaaaa'])->assertOk();
    expect(DB::table('push_tokens')->count())->toBe(1);
});

it('unregisters a push token', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');
    $this->postJson('/api/push/register', ['platform' => 'ios', 'token' => 'tok-bbbbbbbbbb'])->assertOk();
    $this->postJson('/api/push/unregister', ['platform' => 'ios', 'token' => 'tok-bbbbbbbbbb'])->assertOk();
    expect(DB::table('push_tokens')->count())->toBe(0);
});

it('rejects invalid platform', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/push/register', ['platform' => 'desktop', 'token' => 'tok-cccccccc'])
        ->assertStatus(422);
});
