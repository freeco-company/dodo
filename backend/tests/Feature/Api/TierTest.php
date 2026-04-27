<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('FP-* code upgrades to fp_lifetime', function () {
    $user = User::factory()->create(['membership_tier' => 'public']);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/tier/redeem', ['ref_code' => 'FP-WEB-001'])
        ->assertOk()
        ->assertJsonPath('new_tier', 'fp_lifetime')
        ->assertJsonPath('upgraded', true);
});

it('APP-MONTH-* code mocks app_monthly subscription', function () {
    $user = User::factory()->create(['membership_tier' => 'public', 'subscription_type' => 'none']);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/tier/redeem', ['ref_code' => 'APP-MONTH-TEST'])
        ->assertOk()
        ->assertJsonPath('new_subscription', 'app_monthly');
});

it('rejects unknown ref code formats', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/tier/redeem', ['ref_code' => 'BOGUS-CODE'])
        ->assertStatus(422);
});

it('mock subscribe sets app_monthly with 30-day expiry', function () {
    $user = User::factory()->create(['subscription_type' => 'none']);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/subscribe/mock', ['type' => 'app_monthly'])
        ->assertOk()
        ->assertJsonPath('new_subscription', 'app_monthly');
    expect($user->fresh()->subscription_expires_at_iso)->not->toBeNull();
});

it('admin tier endpoint sets membership directly', function () {
    config(['app.admin_token' => 'tier-admin']);
    $user = User::factory()->create(['membership_tier' => 'public']);
    $this->postJson('/api/admin/tier', [
        'user_id' => $user->id,
        'tier' => 'fp_lifetime',
    ], ['X-Admin-Token' => 'tier-admin'])
        ->assertOk()
        ->assertJsonPath('new_tier', 'fp_lifetime');
});
