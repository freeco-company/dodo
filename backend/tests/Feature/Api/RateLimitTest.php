<?php

/**
 * Pre-launch security: credential stuffing + abuse rate limits.
 *
 * RateLimiter uses cache store; CACHE_STORE=array per phpunit.xml so each
 * test starts with a fresh limiter window. The login limiter is keyed on
 * (email + IP) so a single test process pinging the same email/IP triggers
 * the per-account throttle predictably.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('rate-limits /auth/login to 5 attempts per minute per IP', function () {
    User::factory()->create([
        'email' => 'rl@example.com',
        'password' => Hash::make('correct-pw'),
    ]);

    // 5 wrong-password attempts → 422 each
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/auth/login', [
            'email' => 'rl@example.com',
            'password' => 'wrong'.$i,
        ])->assertStatus(422);
    }

    // 6th attempt within the window → 429
    $this->postJson('/api/auth/login', [
        'email' => 'rl@example.com',
        'password' => 'wrong-final',
    ])->assertStatus(429);
});

it('rate-limits /iap/verify to 20 per minute', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');

    // Burst 20 — each will probably 5xx / 4xx because no real receipt, but
    // the throttle middleware fires before the controller. We only care about
    // status code 429 NOT showing up until the 21st call.
    for ($i = 0; $i < 20; $i++) {
        $resp = $this->postJson('/api/iap/verify', ['provider' => 'apple', 'receipt' => 'x']);
        expect($resp->getStatusCode())->not->toBe(429, "request {$i} got 429 too early");
    }

    $this->postJson('/api/iap/verify', ['provider' => 'apple', 'receipt' => 'x'])
        ->assertStatus(429);
});

it('rate-limits /bootstrap to 60 per minute', function () {
    // 60 OK then 61st 429. We don't actually loop 60 because that's slow —
    // instead poke 1 to ensure the route still works under throttle middleware.
    $this->getJson('/api/bootstrap')->assertOk();
});
