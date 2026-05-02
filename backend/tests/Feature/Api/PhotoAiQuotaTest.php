<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * SPEC-photo-ai-calorie-polish §5.1 — daily quota gating.
 *
 * Free 用戶：3 拍照/天，第 4 次 → 402 + paywall payload。
 * Paid 用戶 (subscription_type !== 'none' active OR membership_tier=fp_lifetime)：
 *   unlimited（不消耗 counter，純走 ai-service path）。
 * 跨日：counter 自動 reset（Asia/Taipei 校準）。
 */
beforeEach(function () {
    config()->set('services.meal_ai_service.base_url', 'https://ai.test');
    config()->set('services.meal_ai_service.shared_secret', 'secret');
    config()->set('services.meal_ai_service.timeout', 5);

    Http::fake([
        'ai.test/v1/vision/recognize' => Http::response([
            'items' => [['name' => '便當', 'estimated_kcal' => 720, 'confidence' => 0.92]],
            'overall_confidence' => 0.92,
            'manual_input_required' => false,
            'ai_feedback' => '均衡',
            'model' => 'stub',
            'cost_usd' => 0.001,
            'safety_flags' => [],
            'stub_mode' => true,
        ], 200),
    ]);
});

function _scanPayload(): array
{
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');

    return [
        'photo_base64' => base64_encode($pngBytes),
        'content_type' => 'image/png',
        'meal_type' => 'lunch',
    ];
}

function _freeUser(): User
{
    return User::factory()->create([
        'pandora_user_uuid' => 'uuid-free-'.uniqid(),
        'membership_tier' => 'public',
        'subscription_type' => 'none',
    ]);
}

function _paidUser(): User
{
    return User::factory()->create([
        'pandora_user_uuid' => 'uuid-paid-'.uniqid(),
        'subscription_type' => 'app_monthly',
        'subscription_expires_at_iso' => now()->addMonth(),
    ]);
}

it('free tier allows 3 photo scans per day', function () {
    $user = _freeUser();

    foreach (range(1, 3) as $i) {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/scan', _scanPayload())
            ->assertOk();
    }

    $user->refresh();
    expect((int) $user->photo_ai_used_today)->toBe(3);
});

it('free tier 4th photo scan returns 402 with paywall payload', function () {
    $user = _freeUser();
    $user->photo_ai_used_today = 3;
    $user->photo_ai_reset_at = now('Asia/Taipei')->toDateString();
    $user->save();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', _scanPayload())
        ->assertStatus(402)
        ->assertJsonPath('error_code', 'PHOTO_AI_QUOTA_EXCEEDED')
        ->assertJsonPath('paywall.tier_required', 'paid')
        ->assertJsonPath('paywall.fallback_endpoint', '/api/meals/text');
});

it('paid tier never returns 402 and does not bump the free counter', function () {
    $user = _paidUser();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/meals/scan', _scanPayload())
            ->assertOk();
    }

    $user->refresh();
    // Paid user passes through consumePhotoAiQuota without bumping counter.
    expect((int) $user->photo_ai_used_today)->toBe(0);
});

it('cross-day rollover: yesterday 3 used → today resets to 1 after first scan', function () {
    $user = _freeUser();
    $user->photo_ai_used_today = 3;
    $user->photo_ai_reset_at = now('Asia/Taipei')->subDay()->toDateString();
    $user->save();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/scan', _scanPayload())
        ->assertOk();

    $user->refresh();
    expect((int) $user->photo_ai_used_today)->toBe(1)
        ->and((string) $user->photo_ai_reset_at?->toDateString())
            ->toBe(now('Asia/Taipei')->toDateString());
});

it('text fallback is NOT gated by photo quota (per spec §5.1 fallback path)', function () {
    Http::fake([
        'ai.test/v1/vision/recognize-text' => Http::response([
            'foods' => [['name' => '雞腿便當', 'estimated_kcal' => 720, 'confidence' => 0.9]],
            'total_calories' => 720,
            'confidence' => 0.9,
            'manual_input_required' => false,
            'ai_feedback' => 'ok',
            'model' => 'stub',
            'cost_usd' => 0.001,
            'safety_flags' => [],
            'stub' => true,
        ], 200),
    ]);

    $user = _freeUser();
    $user->photo_ai_used_today = 99;  // way over photo quota
    $user->photo_ai_reset_at = now('Asia/Taipei')->toDateString();
    $user->save();

    // Text endpoint stays free — frontend can fall back here per paywall hint.
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals/text', [
            'description' => '一碗白飯加一塊雞腿',
        ])
        ->assertOk();
});

it('bootstrap entitlements include photo_ai_quota_* fields', function () {
    config()->set('services.pandora_conversion.base_url', 'http://py.test');
    config()->set('services.pandora_conversion.shared_secret', 'x');
    Http::fake([
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'visitor'], 200),
        '*/api/v1/internal/events' => Http::response(['accepted' => true], 202),
    ]);

    $user = _freeUser();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('entitlements.photo_ai_quota_total', 3)
        ->assertJsonPath('entitlements.photo_ai_quota_used', 0)
        ->assertJsonPath('entitlements.photo_ai_quota_remaining', 3);
});
