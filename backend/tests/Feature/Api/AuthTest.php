<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('registers a new user with profile and computes targets', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => '小明',
        'email' => 'xiaoming@example.com',
        'password' => 'Sup3r-Pass-1',
        'height_cm' => 165,
        'current_weight_kg' => 70,
        'target_weight_kg' => 60,
        'birth_date' => '1995-03-15',
        'gender' => 'female',
        'activity_level' => 'light',
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'name', 'targets'], 'token'])
        ->assertJsonPath('data.name', '小明');

    $user = User::where('email', 'xiaoming@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->daily_calorie_target)->toBeGreaterThan(1200)
        ->and($user->daily_protein_target_g)->toBeGreaterThan(0)
        ->and($user->onboarded_at)->not->toBeNull();
});

it('rejects register with raw apple_id until OAuth is wired', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'attacker',
        'apple_id' => 'victim-apple-id-001',
        'height_cm' => 165,
        'current_weight_kg' => 60,
    ]);
    $response->assertStatus(422)
        ->assertJsonValidationErrors('apple_id');
});

it('rejects register with raw line_id until OAuth is wired', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'attacker',
        'line_id' => 'victim-line-id-001',
        'height_cm' => 165,
        'current_weight_kg' => 60,
    ]);
    $response->assertStatus(422)
        ->assertJsonValidationErrors('line_id');
});

it('enforces 10-char mixed-case + digits password policy on register', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'weak',
        'email' => 'weak@example.com',
        'password' => 'short',
        'height_cm' => 165,
        'current_weight_kg' => 60,
    ]);
    $response->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('rejects register with invalid weight', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'X',
        'height_cm' => 165,
        'current_weight_kg' => 5,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors('current_weight_kg');
});

it('logs in with email + password and returns token', function () {
    User::factory()->create([
        'email' => 'login@example.com',
        'password' => Hash::make('correct-pw'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'login@example.com',
        'password' => 'correct-pw',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['id'], 'token']);
});

it('rejects login with wrong password', function () {
    User::factory()->create([
        'email' => 'login2@example.com',
        'password' => Hash::make('correct-pw'),
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'login2@example.com',
        'password' => 'wrong-pw',
    ]);

    $response->assertStatus(422);
});

it('returns 401 for /me when unauthenticated', function () {
    $this->getJson('/api/me')->assertUnauthorized();
});

it('returns user profile via /me with sanctum token', function () {
    $user = User::factory()->create(['name' => '阿花', 'membership_tier' => 'fp_lifetime']);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/me');

    $response->assertOk()
        ->assertJsonPath('data.name', '阿花')
        ->assertJsonPath('data.subscription.membership_tier', 'fp_lifetime');
});

it('logs out and revokes the token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/auth/logout')
        ->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});
