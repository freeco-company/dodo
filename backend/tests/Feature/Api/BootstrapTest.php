<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns app_config without auth (anonymous bootstrap)', function () {
    $this->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonStructure([
            'app_config',
            'entitlements',
            'user',
            'lifecycle' => ['status', 'show_franchise_cta', 'franchise_url'],
        ]);
});

it('returns user entitlements when authenticated', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure(['entitlements' => ['tier', 'subscription', 'island_quota_remaining']]);
});
