<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('tracks an event for an authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/analytics/track', [
            'event' => 'first_meal_logged',
            'properties' => ['source' => 'photo'],
        ])
        ->assertOk();
    expect(DB::table('analytics_events')->where('user_id', $user->id)->where('event', 'first_meal_logged')->exists())
        ->toBeTrue();
});

it('admin flush returns a count without provider', function () {
    config(['app.admin_token' => 'admin-secret']);
    $this->postJson('/api/admin/analytics/flush', [], ['X-Admin-Token' => 'admin-secret'])
        ->assertOk()
        ->assertJsonPath('flushed', 0);
});

it('rejects analytics flush without admin token', function () {
    config(['app.admin_token' => 'admin-secret']);
    $this->postJson('/api/admin/analytics/flush')->assertStatus(403);
});
