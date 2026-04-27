<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns the user referral code (auto-generates)', function () {
    $user = User::factory()->create();
    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/referrals/me')
        ->assertOk()
        ->assertJsonStructure(['code', 'invited_count', 'reward_days_earned', 'invited']);
    expect($resp->json('code'))->toBeString()->not->toBeEmpty();
});

it('redeems a valid referral code and extends both trials', function () {
    $referrer = User::factory()->create(['referral_code' => 'ABCD1234']);
    $referee = User::factory()->create();

    $this->actingAs($referee, 'sanctum')
        ->postJson('/api/referrals/redeem', ['code' => 'ABCD1234'])
        ->assertOk()
        ->assertJsonPath('referrer_id', $referrer->id)
        ->assertJsonPath('reward_kind', 'trial_extension_7d');

    expect(DB::table('referrals')->where('referee_id', $referee->id)->exists())->toBeTrue();
});

it('rejects self-referral', function () {
    $user = User::factory()->create(['referral_code' => 'SELF1234']);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/referrals/redeem', ['code' => 'SELF1234'])
        ->assertStatus(422);
});

it('rejects double-redemption', function () {
    $r1 = User::factory()->create(['referral_code' => 'CODE1111']);
    $referee = User::factory()->create();
    $this->actingAs($referee, 'sanctum');
    $this->postJson('/api/referrals/redeem', ['code' => 'CODE1111'])->assertOk();
    $this->postJson('/api/referrals/redeem', ['code' => 'CODE1111'])->assertStatus(422);
});
