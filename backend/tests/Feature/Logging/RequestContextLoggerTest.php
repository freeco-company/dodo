<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('echoes incoming X-Request-Id back on the response', function () {
    $resp = $this->getJson('/api/health', ['X-Request-Id' => 'req-from-client-123']);

    $resp->assertOk();
    expect($resp->headers->get('X-Request-Id'))->toBe('req-from-client-123');
});

it('generates a uuid request id when client sent none', function () {
    $resp = $this->getJson('/api/health');

    $rid = $resp->headers->get('X-Request-Id');
    expect($rid)
        ->not()->toBeNull()
        ->and(strlen((string) $rid))->toBeGreaterThan(20);
});

it('stamps request id even on authenticated routes', function () {
    $u = User::factory()->create([
        'pandora_user_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeee0001',
    ]);
    Sanctum::actingAs($u);

    $resp = $this->getJson('/api/me');
    $resp->assertOk();
    expect($resp->headers->get('X-Request-Id'))->not()->toBeNull();
});
