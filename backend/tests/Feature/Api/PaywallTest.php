<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('logs a paywall shown event', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/paywall/event', [
            'kind' => 'shown',
            'trigger' => 'scan_quota_exhausted',
        ])
        ->assertOk();
    expect(DB::table('paywall_events')->where('user_id', $user->id)->where('event_kind', 'shown')->exists())
        ->toBeTrue();
});

it('rejects an invalid paywall event kind', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/paywall/event', ['kind' => 'bogus'])
        ->assertStatus(422);
});

it('requires auth on paywall event', function () {
    $this->postJson('/api/paywall/event', ['kind' => 'shown'])->assertStatus(401);
});
