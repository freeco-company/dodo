<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('marks the account for deletion with a 7-day cooldown', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/delete-request')
        ->assertOk()
        ->assertJsonStructure(['hard_delete_after']);
    expect($user->fresh()->deletion_requested_at)->not->toBeNull();
});

it('restores a pending-deletion account', function () {
    $user = User::factory()->create([
        'deletion_requested_at' => now(),
        'hard_delete_after' => now()->addDays(7),
    ]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/restore')
        ->assertOk();
    expect($user->fresh()->deletion_requested_at)->toBeNull();
});

it('rejects restore when not pending', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/account/restore')
        ->assertStatus(422);
});

it('admin purge wipes accounts past their cooldown', function () {
    config(['app.admin_token' => 'test-secret']);
    $expired = User::factory()->create([
        'deletion_requested_at' => now()->subDays(10),
        'hard_delete_after' => now()->subDay(),
    ]);
    $live = User::factory()->create();

    $this->postJson('/api/admin/account/purge-expired', [], ['X-Admin-Token' => 'test-secret'])
        ->assertOk()
        ->assertJsonPath('purged', 1);

    expect(User::find($expired->id))->toBeNull();
    expect(User::find($live->id))->not->toBeNull();
});

it('rejects admin purge without token', function () {
    config(['app.admin_token' => 'test-secret']);
    $this->postJson('/api/admin/account/purge-expired')->assertStatus(403);
});
