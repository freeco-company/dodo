<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns initial journey state', function () {
    $user = User::factory()->create([
        'journey_cycle' => 1,
        'journey_day' => 0,
        'journey_last_advance_date' => null,
        'journey_started_at' => null,
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/journey')
        ->assertOk()
        ->assertJsonPath('cycle', 1)
        ->assertJsonPath('day', 0)
        ->assertJsonPath('total_days', 21)
        ->assertJsonPath('advanced_today', false);
});

it('advances journey by 1 step', function () {
    $user = User::factory()->create([
        'journey_cycle' => 1,
        'journey_day' => 0,
        'journey_last_advance_date' => null,
        'xp' => 0,
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/journey/advance', ['reason' => 'meal_log'])
        ->assertOk()
        ->assertJsonPath('advanced', true)
        ->assertJsonPath('new_day', 1);
});

it('does not double-advance same day', function () {
    $user = User::factory()->create([
        'journey_cycle' => 1, 'journey_day' => 5,
        'journey_last_advance_date' => now()->toDateString(),
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/journey/advance', ['reason' => 'water'])
        ->assertOk()
        ->assertJsonPath('advanced', false);
});
