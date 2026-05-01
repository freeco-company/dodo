<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Apple Sign-In feature tests run in stub mode (AppleIdTokenVerifier bypasses
 * Apple JWKS so we can hand-craft tokens). Stub still parses + validates iss /
 * aud / sub / exp / iat — so a malformed payload is rejected.
 */
beforeEach(function () {
    config()->set('services.apple.stub_mode', true);
    config()->set('services.apple.client_id', 'com.test.pandora.meal');
});

/**
 * Build a stub identity_token shaped like a real JWT (3 dot-separated parts)
 * but with an arbitrary header / signature segment. AppleIdTokenVerifier in
 * stub mode only inspects the payload segment.
 *
 * @param  array<string,mixed>  $payload
 */
function appleStubToken(array $payload): string
{
    $segment = fn (array $a) => rtrim(strtr(base64_encode((string) json_encode($a)), '+/', '-_'), '=');

    return $segment(['alg' => 'RS256', 'kid' => 'stub']).'.'.$segment($payload).'.stub-signature';
}

it('signs in an existing user matched by apple_id', function () {
    $user = User::factory()->create([
        'apple_id' => 'apple-sub-existing-001',
        'name' => '阿花',
    ]);

    $token = appleStubToken([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.test.pandora.meal',
        'sub' => 'apple-sub-existing-001',
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);

    $response = $this->postJson('/api/auth/apple', ['identity_token' => $token]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name'], 'token'])
        ->assertJsonPath('data.id', $user->id);
});

it('creates a new user when no apple_id match and no email match', function () {
    $token = appleStubToken([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.test.pandora.meal',
        'sub' => 'apple-sub-new-002',
        'email' => 'newbie@example.com',
        'email_verified' => true,
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);

    $response = $this->postJson('/api/auth/apple', ['identity_token' => $token]);

    $response->assertOk();
    $user = User::where('apple_id', 'apple-sub-new-002')->first();
    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('newbie@example.com')
        ->and($user->trial_expires_at)->not->toBeNull();
});

it('merges by verified email when apple_id is new but email already exists', function () {
    $existing = User::factory()->create([
        'email' => 'merge-me@example.com',
        'apple_id' => null,
    ]);

    $token = appleStubToken([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.test.pandora.meal',
        'sub' => 'apple-sub-merge-003',
        'email' => 'merge-me@example.com',
        'email_verified' => true,
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);

    $response = $this->postJson('/api/auth/apple', ['identity_token' => $token]);

    $response->assertOk()->assertJsonPath('data.id', $existing->id);
    $existing->refresh();
    expect($existing->apple_id)->toBe('apple-sub-merge-003');
    // No duplicate user with that email
    expect(User::where('email', 'merge-me@example.com')->count())->toBe(1);
});

it('rejects malformed identity_token with 401', function () {
    $response = $this->postJson('/api/auth/apple', [
        'identity_token' => 'not-even-a-jwt',
    ]);
    $response->assertStatus(401);
});

it('rejects identity_token with wrong issuer', function () {
    $token = appleStubToken([
        'iss' => 'https://evil.example.com',
        'aud' => 'com.test.pandora.meal',
        'sub' => 'attacker',
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);
    $this->postJson('/api/auth/apple', ['identity_token' => $token])
        ->assertStatus(401);
});

it('rejects identity_token with wrong audience', function () {
    $token = appleStubToken([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.someone.else',
        'sub' => 'attacker',
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);
    $this->postJson('/api/auth/apple', ['identity_token' => $token])
        ->assertStatus(401);
});

it('rejects expired identity_token', function () {
    $token = appleStubToken([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.test.pandora.meal',
        'sub' => 'expired',
        'iat' => time() - 7200,
        'exp' => time() - 60,
    ]);
    $this->postJson('/api/auth/apple', ['identity_token' => $token])
        ->assertStatus(401);
});

it('rate-limits to 10 per 60 minutes', function () {
    // 11th request hits 429. Sending malformed token (cheap, no DB writes) is
    // sufficient — throttle middleware fires before the controller body.
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/auth/apple', ['identity_token' => "bad-{$i}"])
            ->assertStatus(401);
    }
    $this->postJson('/api/auth/apple', ['identity_token' => 'bad-11'])
        ->assertStatus(429);
});

it('does not merge when email_verified is false (anti-takeover)', function () {
    $existing = User::factory()->create([
        'email' => 'unverified-target@example.com',
        'apple_id' => null,
    ]);

    $token = appleStubToken([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.test.pandora.meal',
        'sub' => 'apple-sub-attacker',
        'email' => 'unverified-target@example.com',
        'email_verified' => false,
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);

    $response = $this->postJson('/api/auth/apple', ['identity_token' => $token]);

    $response->assertOk();
    // Existing victim row untouched.
    $existing->refresh();
    expect($existing->apple_id)->toBeNull();
    // A separate new user was created for the attacker's apple_id.
    $attackerUser = User::where('apple_id', 'apple-sub-attacker')->first();
    expect($attackerUser)->not->toBeNull()
        ->and($attackerUser->id)->not->toBe($existing->id);
});

it('denies trial when apple_id was previously deleted (fraud blacklist)', function () {
    DB::table('oauth_trial_blacklist')->insert([
        'provider' => 'apple',
        'provider_sub' => 'apple-sub-recycled-009',
        'blacklisted_at' => now(),
        'reason' => 'account_deleted',
    ]);

    $token = appleStubToken([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'com.test.pandora.meal',
        'sub' => 'apple-sub-recycled-009',
        'email' => 'recycled@example.com',
        'email_verified' => true,
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);

    $this->postJson('/api/auth/apple', ['identity_token' => $token])->assertOk();

    $user = User::where('apple_id', 'apple-sub-recycled-009')->first();
    expect($user)->not->toBeNull()
        ->and($user->trial_started_at)->toBeNull()
        ->and($user->trial_expires_at)->toBeNull();
});
