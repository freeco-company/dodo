<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns config + content_version without auth (anonymous bootstrap)', function () {
    $this->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonStructure([
            'config',
            'app_config',
            'content_version',
            'entitlements',
            'user',
            'lifecycle' => ['status', 'show_franchise_cta', 'franchise_url'],
        ])
        ->assertJsonPath('settings', null);
});

it('returns user entitlements + settings when authenticated', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonStructure([
            'entitlements' => ['tier', 'subscription', 'island_quota_remaining'],
            'settings' => ['daily_water_goal_ml', 'dietary_type', 'allergies'],
        ]);
});
