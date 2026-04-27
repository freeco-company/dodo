<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('accepts ecommerce webhook and upgrades matched user', function () {
    $user = User::factory()->create(['email' => 'buyer@example.com', 'membership_tier' => 'public']);

    $this->postJson('/api/webhooks/ecommerce/order', [
        'order_id' => 'ORD-2026-0001',
        'email' => 'buyer@example.com',
    ])->assertOk()
        ->assertJsonPath('matched', true)
        ->assertJsonPath('result.upgraded', true);

    expect($user->fresh()->membership_tier)->toBe('fp_lifetime');
});

it('accepts ecommerce webhook even when user not found', function () {
    $this->postJson('/api/webhooks/ecommerce/order', [
        'order_id' => 'ORD-NOEXIST',
        'email' => 'ghost@example.com',
    ])->assertOk()
        ->assertJsonPath('matched', false);
});

it('rejects ecommerce webhook missing order_id', function () {
    $this->postJson('/api/webhooks/ecommerce/order', [])->assertStatus(422);
});
