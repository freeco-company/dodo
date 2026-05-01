<?php

/**
 * 個資法 §10 right-to-access — /api/me/data-export delivers the user's
 * full pandora-meal dataset as a downloadable JSON file.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a JSON attachment with the user dataset', function () {
    $user = User::factory()->create([
        'name' => 'Exporter',
        'email' => 'export@example.com',
    ]);
    $user->meals()->create([
        'date' => date('Y-m-d'),
        'meal_type' => 'breakfast',
        'food_name' => 'oats',
        'calories' => 300,
        'matched_food_ids' => [],
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/me/data-export')
        ->assertOk();

    $resp->assertHeader('Content-Type', 'application/json');
    expect($resp->headers->get('Content-Disposition'))
        ->toContain('attachment')
        ->toContain('pandora-meal-export-');

    $body = $resp->json();
    expect($body)->toHaveKeys([
        'exported_at', 'app', 'profile', 'meals', 'daily_logs', 'achievements', 'food_discoveries',
    ]);
    expect($body['app'])->toBe('pandora-meal');
    expect($body['profile']['email'])->toBe('export@example.com');
    expect($body['meals'])->toHaveCount(1);
});

it('rejects /me/data-export without auth', function () {
    $this->getJson('/api/me/data-export')->assertUnauthorized();
});
