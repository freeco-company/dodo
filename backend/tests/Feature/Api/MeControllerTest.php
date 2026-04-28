<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns dashboard for authenticated user', function () {
    $user = User::factory()->create([
        'daily_calorie_target' => 1800,
        'daily_protein_target_g' => 80,
    ]);
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/me/dashboard')
        ->assertOk()
        ->assertJsonStructure([
            'user' => ['id', 'name', 'avatar_animal', 'subscription_tier', 'equipped_outfit'],
            'doudou' => [
                'level', 'level_name', 'xp', 'xp_next_level', 'xp_progress',
                'streak', 'longest_streak', 'streak_shields', 'friendship',
                'mood', 'mood_phrase',
            ],
            'today' => ['date', 'calories', 'calories_target', 'remaining_calories', 'protein_g', 'meals'],
            'progress' => ['level', 'xp', 'current_streak'],
            'tasks',
            'last7',
            'achievements',
        ])
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('today.calories_target', 1800);
});

it('rejects dashboard without auth', function () {
    $this->getJson('/api/me/dashboard')->assertUnauthorized();
});

it('returns settings for authenticated user', function () {
    $user = User::factory()->create([
        'dietary_type' => 'vegetarian',
        'allergies' => ['peanut'],
        'push_enabled' => true,
    ]);
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/me/settings')
        ->assertOk()
        ->assertJsonStructure(['daily_water_goal_ml'])
        ->assertJsonPath('dietary_type', 'vegetarian')
        ->assertJsonPath('allergies', ['peanut'])
        ->assertJsonPath('push_enabled', true);
});

it('patches settings for authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->patchJson('/api/me/settings', [
            'push_enabled' => false,
            'dietary_type' => 'low_carb',
        ])
        ->assertOk()
        ->assertJsonPath('push_enabled', false)
        ->assertJsonPath('dietary_type', 'low_carb');
    expect($user->fresh()->dietary_type)->toBe('low_carb');
});

it('rejects settings without auth', function () {
    $this->getJson('/api/me/settings')->assertUnauthorized();
});
