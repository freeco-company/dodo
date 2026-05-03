<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Contract tests for POST /api/meals/scan + /api/meals/text.
 *
 * Regression: issue #88 — frontend posts {photo_base64} but the controller
 * used to only accept {image_url}, leaving the camera flow dead-on-arrival.
 */
beforeEach(function () {
    config()->set('services.meal_ai_service.base_url', 'https://ai.test');
    config()->set('services.meal_ai_service.shared_secret', 'secret');
    config()->set('services.meal_ai_service.timeout', 5);
});

it('scan accepts photo_base64 and forwards bytes as multipart', function () {
    Http::fake([
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

    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    $base64 = base64_encode($pngBytes);

    $user = User::factory()->create(['pandora_user_uuid' => '00000000-0000-0000-0000-aaaaaaaaaaaa']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', [
            'photo_base64' => $base64,
            'content_type' => 'image/png',
            'meal_type' => 'dinner',
        ])
        ->assertOk()
        ->assertJsonPath('items.0.name', '雞腿便當');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://ai.test/v1/vision/recognize'
            && $request->hasHeader('X-Internal-Secret', 'secret')
            && $request->hasHeader('X-Pandora-User-Uuid', '00000000-0000-0000-0000-aaaaaaaaaaaa');
    });
});

it('scan still accepts image_url for back-compat', function () {
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

    Http::fake([
        'x.test/*' => Http::response($pngBytes, 200, ['Content-Type' => 'image/png']),
        'ai.test/v1/vision/recognize' => Http::response([
            'items' => [], 'overall_confidence' => 0.5, 'manual_input_required' => true,
            'ai_feedback' => '', 'model' => 'stub', 'cost_usd' => 0.0,
            'safety_flags' => [], 'stub_mode' => true,
        ], 200),
    ]);

    $user = User::factory()->create(['pandora_user_uuid' => 'uuid-1']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', ['image_url' => 'https://x.test/img.png'])
        ->assertOk();
});

it('scan requires photo_base64 OR image_url', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo_base64', 'image_url']);
});

it('scan rejects supplying both photo_base64 and image_url', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', [
            'photo_base64' => base64_encode('xx'),
            'image_url' => 'https://x.test/img.png',
        ])
        ->assertStatus(422);
});

it('scan returns 422 when photo_base64 is not valid base64', function () {
    $user = User::factory()->create();

    // Strict base64 validation rejects characters outside the alphabet.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', ['photo_base64' => '!!!not-base64!!!'])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'INVALID_BASE64');
});

it('scan rejects oversized photo_base64', function () {
    $user = User::factory()->create();

    $huge = str_repeat('A', 5_000_001); // > 5MB cap

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', ['photo_base64' => $huge])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['photo_base64']);
});

it('scan rejects unknown content_type', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', [
            'photo_base64' => base64_encode('xx'),
            'content_type' => 'application/pdf',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['content_type']);
});

it('scan rejects unknown meal_type', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', [
            'photo_base64' => base64_encode('xx'),
            'meal_type' => 'midnight-feast',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['meal_type']);
});

it('scan returns 503 when ai-service base_url is not configured', function () {
    config()->set('services.meal_ai_service.base_url', '');
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', ['photo_base64' => base64_encode('xx')])
        ->assertStatus(503)
        ->assertJsonPath('error_code', 'AI_SERVICE_DOWN');
});

it('scan requires authentication', function () {
    $this->postJson('/api/meals/scan', ['photo_base64' => base64_encode('xx')])
        ->assertStatus(401);
});

// SPEC-photo-ai-correction-v2 PR #4.5 — auto-materialize Meal + dishes from scan items[].
it('scan auto-materializes a Meal + MealDish rows from items[]', function () {
    Http::fake([
        'ai.test/v1/vision/recognize' => Http::response([
            'items' => [
                ['name' => '白飯', 'estimated_kcal' => 320, 'confidence' => 0.92,
                 'macro_grams' => ['carb' => 70, 'protein' => 6, 'fat' => 0.5]],
                ['name' => '雞腿', 'estimated_kcal' => 280, 'confidence' => 0.88,
                 'macro_grams' => ['carb' => 0, 'protein' => 35, 'fat' => 15]],
            ],
            'overall_confidence' => 0.90,
            'manual_input_required' => false,
            'ai_feedback' => '看起來均衡',
            'model' => 'stub',
            'cost_usd' => 0.001,
            'safety_flags' => [],
            'stub_mode' => true,
            'is_food' => true,
            'dodo_comment' => '吃得均衡 🌷',
        ], 200),
    ]);

    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-auto-meal']);

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', [
            'photo_base64' => base64_encode($pngBytes),
            'content_type' => 'image/png',
            'meal_type' => 'lunch',
        ])
        ->assertOk();

    expect($resp->json('meal'))->not->toBeNull();
    expect($resp->json('meal.dishes'))->toHaveCount(2);
    expect($resp->json('meal.dishes.0.food_name'))->toBe('白飯');
    expect($resp->json('meal.dishes.0.confidence_band'))->toBe('high');
    expect($resp->json('meal.macros.calories'))->toBe(600);

    $mealId = $resp->json('meal.id');
    expect(\App\Models\MealDish::where('meal_id', $mealId)->count())->toBe(2);
});

it('scan does NOT materialize when is_food is false', function () {
    Http::fake([
        'ai.test/v1/vision/recognize' => Http::response([
            'items' => [],
            'overall_confidence' => 0.5,
            'manual_input_required' => true,
            'ai_feedback' => '看起來不是食物',
            'model' => 'stub',
            'cost_usd' => 0.0,
            'safety_flags' => ['not_food'],
            'stub_mode' => true,
            'is_food' => false,
        ], 200),
    ]);

    $user = User::factory()->create(['pandora_user_uuid' => 'u-not-food']);

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', [
            'photo_base64' => base64_encode('xx'),
        ])
        ->assertOk();

    expect($resp->json('meal'))->toBeNull();
    expect($user->meals()->count())->toBe(0);
});

it('scan falls back to 40/30/30 macro split when ai-service omits macro_grams', function () {
    Http::fake([
        'ai.test/v1/vision/recognize' => Http::response([
            'items' => [
                ['name' => '便當', 'estimated_kcal' => 800, 'confidence' => 0.9],
            ],
            'overall_confidence' => 0.9,
            'manual_input_required' => false,
            'ai_feedback' => '',
            'model' => 'stub', 'cost_usd' => 0.001,
            'safety_flags' => [], 'stub_mode' => true, 'is_food' => true,
        ], 200),
    ]);

    $user = User::factory()->create(['pandora_user_uuid' => 'u-fallback-macro']);

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', ['photo_base64' => base64_encode('xx')])
        ->assertOk();

    $dish = $resp->json('meal.dishes.0');
    expect((int) $dish['kcal'])->toBe(800);
    expect((float) $dish['carb_g'])->toBe(80.0);     // 800 * .4 / 4
    expect((float) $dish['protein_g'])->toBe(60.0);  // 800 * .3 / 4
    expect((float) $dish['fat_g'])->toBe(26.7);      // 800 * .3 / 9
});
