<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ECOM_SECRET = 'test-ecommerce-secret';

beforeEach(function () {
    config()->set('services.meal_ecommerce_webhook.secret', ECOM_SECRET);
    config()->set('services.meal_ecommerce_webhook.window_seconds', 300);
});

/**
 * Build the signed POST headers + body the publisher would send.
 *
 * @param  array<string, mixed>  $payload
 * @return array{headers: array<string, string>, body: string}
 */
function signedEcommercePayload(array $payload): array
{
    $body = json_encode($payload);
    $ts = now()->toIso8601String();
    $sig = 'sha256='.hash_hmac('sha256', "{$ts}.{$body}", ECOM_SECRET);

    return [
        'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Pandora-Timestamp' => $ts,
            'X-Pandora-Signature' => $sig,
        ],
        'body' => $body,
    ];
}

it('rejects unsigned ecommerce webhook with 401', function () {
    test()->postJson('/api/webhooks/ecommerce/order', [
        'order_id' => 'ORD-X',
    ])->assertStatus(401);
});

it('rejects bad signature with 401', function () {
    $signed = signedEcommercePayload(['order_id' => 'ORD-X']);
    $headers = $signed['headers'];
    $headers['X-Pandora-Signature'] = 'sha256=deadbeef';

    test()->call('POST', '/api/webhooks/ecommerce/order', [], [], [], headersToServer($headers), $signed['body'])
        ->assertStatus(401);
});

it('rejects when secret not configured (503 fail-closed)', function () {
    config()->set('services.meal_ecommerce_webhook.secret', '');

    $signed = signedEcommercePayload(['order_id' => 'ORD-X']);
    test()->call('POST', '/api/webhooks/ecommerce/order', [], [], [], headersToServer($signed['headers']), $signed['body'])
        ->assertStatus(503);
});

it('accepts ecommerce webhook with valid signature and upgrades matched user', function () {
    $user = User::factory()->create(['email' => 'buyer@example.com', 'membership_tier' => 'public']);

    $signed = signedEcommercePayload([
        'order_id' => 'ORD-2026-0001',
        'email' => 'buyer@example.com',
    ]);

    test()->call('POST', '/api/webhooks/ecommerce/order', [], [], [], headersToServer($signed['headers']), $signed['body'])
        ->assertOk()
        ->assertJsonPath('matched', true)
        ->assertJsonPath('result.upgraded', true);

    expect($user->fresh()->membership_tier)->toBe('fp_lifetime');
});

it('accepts ecommerce webhook even when user not found', function () {
    $signed = signedEcommercePayload([
        'order_id' => 'ORD-NOEXIST',
        'email' => 'ghost@example.com',
    ]);
    test()->call('POST', '/api/webhooks/ecommerce/order', [], [], [], headersToServer($signed['headers']), $signed['body'])
        ->assertOk()
        ->assertJsonPath('matched', false);
});

it('rejects ecommerce webhook missing order_id (after signature passes)', function () {
    $signed = signedEcommercePayload([]);
    test()->call('POST', '/api/webhooks/ecommerce/order', [], [], [], headersToServer($signed['headers']), $signed['body'])
        ->assertStatus(422);
});

it('rejects timestamp out of window with 401', function () {
    $body = json_encode(['order_id' => 'ORD-X']);
    $oldTs = now()->subHour()->toIso8601String();
    $sig = 'sha256='.hash_hmac('sha256', "{$oldTs}.{$body}", ECOM_SECRET);

    test()->call('POST', '/api/webhooks/ecommerce/order', [], [], [], headersToServer([
        'Content-Type' => 'application/json',
        'X-Pandora-Timestamp' => $oldTs,
        'X-Pandora-Signature' => $sig,
    ]), $body)->assertStatus(401);
});

/**
 * @param  array<string, string>  $headers
 * @return array<string, string>
 */
function headersToServer(array $headers): array
{
    $server = [];
    foreach ($headers as $k => $v) {
        $key = 'HTTP_'.strtoupper(str_replace('-', '_', $k));
        $server[$key] = $v;
    }
    if (isset($headers['Content-Type'])) {
        $server['CONTENT_TYPE'] = $headers['Content-Type'];
        unset($server['HTTP_CONTENT_TYPE']);
    }

    return $server;
}
