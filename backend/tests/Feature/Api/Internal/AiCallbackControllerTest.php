<?php

use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * SPEC-photo-ai-calorie-polish §4.2 — internal callback receivers.
 *
 *   POST /api/internal/ai-callback/food-recognition  (analytics log only)
 *   POST /api/internal/ai-callback/cost-event        (writes usage_logs)
 *
 * Auth：X-Internal-Secret header must match services.meal_ai_service.shared_secret.
 */
beforeEach(function () {
    config()->set('services.meal_ai_service.shared_secret', 'callback-secret');
});

it('food-recognition rejects missing X-Internal-Secret', function () {
    $this->postJson('/api/internal/ai-callback/food-recognition', [
        'userUuid' => 'uuid-x', 'mealType' => 'lunch',
    ])->assertStatus(401);
});

it('food-recognition rejects wrong X-Internal-Secret', function () {
    $this->withHeaders(['X-Internal-Secret' => 'wrong'])
        ->postJson('/api/internal/ai-callback/food-recognition', [
            'userUuid' => 'uuid-x',
        ])->assertStatus(401);
});

it('food-recognition accepts authorized log + returns ok', function () {
    $this->withHeaders(['X-Internal-Secret' => 'callback-secret'])
        ->postJson('/api/internal/ai-callback/food-recognition', [
            'userUuid' => 'uuid-x',
            'mealType' => 'lunch',
            'items' => [['name' => '便當', 'estimated_kcal' => 720]],
            'confidence' => 0.92,
            'manualInputRequired' => false,
            'aiFeedback' => '看起來不錯',
            'model' => 'claude-3-5-sonnet',
            'costUsd' => 0.003,
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('food-recognition validates schema (rejects missing userUuid)', function () {
    $this->withHeaders(['X-Internal-Secret' => 'callback-secret'])
        ->postJson('/api/internal/ai-callback/food-recognition', [
            'mealType' => 'lunch',
        ])->assertStatus(422);
});

it('cost-event writes usage_logs row keyed to user', function () {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'uuid-cost-event-001',
    ]);

    $this->withHeaders(['X-Internal-Secret' => 'callback-secret'])
        ->postJson('/api/internal/ai-callback/cost-event', [
            'userUuid' => 'uuid-cost-event-001',
            'endpoint' => '/v1/vision/recognize',
            'model' => 'claude-3-5-sonnet',
            'tokensIn' => 1200,
            'tokensOut' => 350,
            'costUsd' => 0.018,
        ])
        ->assertOk();

    $row = UsageLog::query()->where('user_id', $user->id)->latest('id')->first();
    expect($row)->not->toBeNull()
        ->and($row->kind)->toBe('vision')
        ->and((int) $row->input_tokens)->toBe(1200)
        ->and((int) $row->output_tokens)->toBe(350);
});

it('cost-event with unknown uuid returns 200 with noted reason', function () {
    $this->withHeaders(['X-Internal-Secret' => 'callback-secret'])
        ->postJson('/api/internal/ai-callback/cost-event', [
            'userUuid' => 'uuid-does-not-exist',
            'endpoint' => '/v1/vision/recognize',
            'model' => 'claude-3-5-sonnet',
            'tokensIn' => 100,
            'tokensOut' => 50,
        ])
        ->assertOk()
        ->assertJsonPath('noted', 'user_not_found');
});

it('cost-event maps endpoint to kind correctly (chat / vision / other)', function (string $endpoint, string $expectedKind) {
    $user = User::factory()->create([
        'pandora_user_uuid' => 'uuid-kind-'.$expectedKind,
    ]);

    $this->withHeaders(['X-Internal-Secret' => 'callback-secret'])
        ->postJson('/api/internal/ai-callback/cost-event', [
            'userUuid' => $user->pandora_user_uuid,
            'endpoint' => $endpoint,
            'model' => 'claude-3-5-sonnet',
            'tokensIn' => 100,
            'tokensOut' => 50,
        ])->assertOk();

    expect(UsageLog::query()->where('user_id', $user->id)->latest('id')->first()->kind)
        ->toBe($expectedKind);
})->with([
    ['/v1/vision/recognize', 'vision'],
    ['/v1/chat/stream', 'chat'],
    ['/v1/foo/bar', 'other'],
]);
