<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs water and returns total + capped flag', function () {
    $user = User::factory()->create(['daily_calorie_target' => 1800, 'daily_protein_target_g' => 80]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => 500])
        ->assertOk()
        ->assertJsonPath('water_ml', 500)
        ->assertJsonPath('capped', false);
});

it('caps repeated water at 5000ml', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    $this->postJson('/api/checkin/water', ['ml' => 4000])->assertOk();
    $this->postJson('/api/checkin/water', ['ml' => 4000])
        ->assertOk()
        ->assertJsonPath('water_ml', 5000)
        ->assertJsonPath('capped', true);
});

it('rejects negative water', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water', ['ml' => -10])
        ->assertStatus(422);
});

it('logs exercise', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/exercise', ['minutes' => 30])
        ->assertOk()
        ->assertJsonPath('exercise_minutes', 30);
});

it('sets water directly (override)', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/water/set', ['ml' => 1500])
        ->assertOk()
        ->assertJsonPath('water_ml', 1500);
});

it('sets exercise directly (override)', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/exercise/set', ['minutes' => 45])
        ->assertOk()
        ->assertJsonPath('exercise_minutes', 45);
});

it('logs weight and updates user current weight', function () {
    $user = User::factory()->create(['xp' => 0, 'level' => 1]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/checkin/weight', ['weight_kg' => 65.5])
        ->assertOk()
        ->assertJsonPath('weight_kg', 65.5)
        ->assertJsonPath('xp_gained', 5);
    expect($user->fresh()->current_weight_kg)->toEqual(65.5);
});

it('returns checkin goals', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/checkin/goals')
        ->assertOk()
        ->assertJsonPath('water_goal_ml', 2000)
        ->assertJsonPath('exercise_goal_min', 30);
});
