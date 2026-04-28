<?php

use App\Exceptions\AiServiceUnavailableException;
use App\Models\User;
use App\Services\AiServiceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Targeted unit-ish tests for AiServiceClient — controllers tested separately.
 *
 * We use Http::fake() rather than mocking the client itself to exercise the
 * real request shape (headers / multipart / payload).
 */
beforeEach(function () {
    config()->set('services.dodo_ai_service.base_url', 'https://ai.test');
    config()->set('services.dodo_ai_service.shared_secret', 'test-shared-secret');
    config()->set('services.dodo_ai_service.timeout', 5);
});

it('throws AiServiceUnavailableException when base_url is missing', function () {
    config()->set('services.dodo_ai_service.base_url', '');
    $user = User::factory()->create(['pandora_user_uuid' => 'uuid-1']);

    expect(fn () => app(AiServiceClient::class)->scanMeal($user, 'https://x.test/img.jpg'))
        ->toThrow(AiServiceUnavailableException::class);
});

it('throws AiServiceUnavailableException when shared_secret is missing', function () {
    config()->set('services.dodo_ai_service.shared_secret', '');
    $user = User::factory()->create(['pandora_user_uuid' => 'uuid-1']);

    expect(fn () => app(AiServiceClient::class)->scanMeal($user, 'https://x.test/img.jpg'))
        ->toThrow(AiServiceUnavailableException::class);
});

it('scanMeal POSTs multipart with the right headers + uuid', function () {
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

    Http::fake([
        'x.test/*' => Http::response($pngBytes, 200, ['Content-Type' => 'image/png']),
        'ai.test/v1/vision/recognize' => Http::response([
            'items' => [['name' => '雞腿便當', 'estimated_kcal' => 720, 'confidence' => 0.92]],
            'overall_confidence' => 0.92,
            'manual_input_required' => false,
            'ai_feedback' => '看起來很均衡',
            'model' => 'stub',
            'cost_usd' => 0.001,
            'safety_flags' => [],
            'stub_mode' => true,
        ], 200),
    ]);

    $user = User::factory()->create(['pandora_user_uuid' => '00000000-0000-0000-0000-aaaaaaaaaaaa']);
    $result = app(AiServiceClient::class)->scanMeal($user, 'https://x.test/img.png', ['meal_type' => 'dinner']);

    expect($result['items'][0]['name'])->toBe('雞腿便當');

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://ai.test/v1/vision/recognize') {
            return false;
        }

        return $request->hasHeader('X-Internal-Secret', 'test-shared-secret')
            && $request->hasHeader('X-Pandora-User-Uuid', '00000000-0000-0000-0000-aaaaaaaaaaaa');
    });
});

it('scanMeal raises AI_SERVICE_ERROR on 5xx upstream (after retry)', function () {
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

    Http::fake([
        'x.test/*' => Http::response($pngBytes, 200, ['Content-Type' => 'image/png']),
        'ai.test/*' => Http::response(['detail' => 'boom'], 500),
    ]);

    $user = User::factory()->create(['pandora_user_uuid' => 'uuid-1']);

    expect(fn () => app(AiServiceClient::class)->scanMeal($user, 'https://x.test/img.png'))
        ->toThrow(AiServiceUnavailableException::class);
});

it('scanMeal raises IMAGE_FETCH_FAILED when image url returns 4xx', function () {
    Http::fake([
        'x.test/*' => Http::response('not found', 404),
    ]);

    $user = User::factory()->create(['pandora_user_uuid' => 'uuid-1']);

    expect(fn () => app(AiServiceClient::class)->scanMeal($user, 'https://x.test/missing.png'))
        ->toThrow(AiServiceUnavailableException::class, 'image url returned 404');
});

it('describeMeal explicitly throws AI_TEXT_ENDPOINT_PENDING (endpoint not built yet)', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'uuid-1']);

    try {
        app(AiServiceClient::class)->describeMeal($user, '一個雞腿便當');
        expect(false)->toBeTrue('expected exception');
    } catch (AiServiceUnavailableException $e) {
        expect($e->errorCode)->toBe('AI_TEXT_ENDPOINT_PENDING');
    }
});
