<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.line.stub_mode', true);
    config()->set('services.line.channel_id', 'line-channel-test-1');
});

/** @param  array<string,mixed>  $payload */
function lineStubToken(array $payload): string
{
    $segment = fn (array $a) => rtrim(strtr(base64_encode((string) json_encode($a)), '+/', '-_'), '=');

    return $segment(['alg' => 'HS256']).'.'.$segment($payload).'.stub-signature';
}

it('signs in an existing user matched by line_id', function () {
    $user = User::factory()->create([
        'line_id' => 'line-sub-existing-001',
    ]);

    $token = lineStubToken([
        'iss' => 'https://access.line.me',
        'aud' => 'line-channel-test-1',
        'sub' => 'line-sub-existing-001',
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);

    $response = $this->postJson('/api/auth/line', ['id_token' => $token]);

    $response->assertOk()
        ->assertJsonStructure(['data' => ['id', 'name'], 'token'])
        ->assertJsonPath('data.id', $user->id);
});

it('creates a new user when no line_id match', function () {
    $token = lineStubToken([
        'iss' => 'https://access.line.me',
        'aud' => 'line-channel-test-1',
        'sub' => 'line-sub-new-002',
        'email' => 'line-newbie@example.com',
        'name' => 'LINE 朋友',
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);

    $this->postJson('/api/auth/line', ['id_token' => $token])->assertOk();

    $user = User::where('line_id', 'line-sub-new-002')->first();
    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('line-newbie@example.com')
        ->and($user->name)->toBe('LINE 朋友')
        ->and($user->trial_expires_at)->not->toBeNull();
});

it('merges by email when line_id is new but email exists', function () {
    $existing = User::factory()->create([
        'email' => 'merge@example.com',
        'line_id' => null,
    ]);

    $token = lineStubToken([
        'iss' => 'https://access.line.me',
        'aud' => 'line-channel-test-1',
        'sub' => 'line-sub-merge-003',
        'email' => 'merge@example.com',
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);

    $this->postJson('/api/auth/line', ['id_token' => $token])
        ->assertOk()
        ->assertJsonPath('data.id', $existing->id);

    $existing->refresh();
    expect($existing->line_id)->toBe('line-sub-merge-003');
});

it('rejects malformed id_token with 401', function () {
    $this->postJson('/api/auth/line', ['id_token' => 'not-a-jwt'])
        ->assertStatus(401);
});

it('rejects id_token with wrong issuer', function () {
    $token = lineStubToken([
        'iss' => 'https://evil.example.com',
        'aud' => 'line-channel-test-1',
        'sub' => 'attacker',
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);
    $this->postJson('/api/auth/line', ['id_token' => $token])
        ->assertStatus(401);
});

it('rejects id_token with wrong audience', function () {
    $token = lineStubToken([
        'iss' => 'https://access.line.me',
        'aud' => 'someone-else-channel',
        'sub' => 'attacker',
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);
    $this->postJson('/api/auth/line', ['id_token' => $token])
        ->assertStatus(401);
});

it('rejects expired id_token', function () {
    $token = lineStubToken([
        'iss' => 'https://access.line.me',
        'aud' => 'line-channel-test-1',
        'sub' => 'expired',
        'iat' => time() - 7200,
        'exp' => time() - 60,
    ]);
    $this->postJson('/api/auth/line', ['id_token' => $token])
        ->assertStatus(401);
});

it('rate-limits to 10 per 60 minutes', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/auth/line', ['id_token' => "bad-{$i}"])
            ->assertStatus(401);
    }
    $this->postJson('/api/auth/line', ['id_token' => 'bad-11'])
        ->assertStatus(429);
});

it('denies trial when line_id was previously deleted (fraud blacklist)', function () {
    DB::table('oauth_trial_blacklist')->insert([
        'provider' => 'line',
        'provider_sub' => 'line-sub-recycled-009',
        'blacklisted_at' => now(),
        'reason' => 'account_deleted',
    ]);

    $token = lineStubToken([
        'iss' => 'https://access.line.me',
        'aud' => 'line-channel-test-1',
        'sub' => 'line-sub-recycled-009',
        'iat' => time() - 30,
        'exp' => time() + 600,
    ]);

    $this->postJson('/api/auth/line', ['id_token' => $token])->assertOk();

    $user = User::where('line_id', 'line-sub-recycled-009')->first();
    expect($user)->not->toBeNull()
        ->and($user->trial_started_at)->toBeNull()
        ->and($user->trial_expires_at)->toBeNull();
});
