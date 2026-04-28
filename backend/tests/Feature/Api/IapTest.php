<?php

use App\Models\IapWebhookEvent;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.iap.stub_mode' => true]);
});

it('verify happy path activates a stub apple subscription', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum');
    $this->postJson('/api/iap/verify', [
        'platform' => 'apple',
        'receipt' => 'STUB_APPLE_M_abc123',
        'product_id' => 'dodo.subscription.monthly',
    ])
        ->assertOk()
        ->assertJsonPath('state', 'active')
        ->assertJsonPath('plan', 'app_monthly');

    expect(Subscription::where('user_id', $user->id)->where('state', 'active')->count())->toBe(1);
    // Observer mirrored to legacy User columns:
    expect($user->fresh()->subscription_type)->toBe('app_monthly');
});

it('returns 503 when keys missing and stub mode off', function () {
    config(['services.iap.stub_mode' => false]);
    config(['services.iap.apple.shared_secret' => '']);

    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum');
    $this->postJson('/api/iap/verify', [
        'platform' => 'apple',
        'receipt' => 'real-receipt-blob',
    ])
        ->assertStatus(503)
        ->assertJsonPath('error', 'IAP_NOT_CONFIGURED');
});

it('rejects unknown stub receipt format', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum');
    $this->postJson('/api/iap/verify', [
        'platform' => 'apple',
        'receipt' => 'not-a-stub-token',
    ])
        ->assertStatus(500); // RuntimeException → 500 (not configured fault)
});

it('google stub yearly maps to app_yearly plan', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'sanctum');
    $this->postJson('/api/iap/verify', [
        'platform' => 'google',
        'purchase_token' => 'STUB_GOOGLE_Y_xyz789',
        'product_id' => 'dodo.subscription.yearly',
    ])
        ->assertOk()
        ->assertJsonPath('plan', 'app_yearly');
});

it('apple webhook idempotent on notificationUUID', function () {
    $user = User::factory()->create();
    // Seed a subscription so the notification has something to mutate.
    Subscription::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'provider' => 'apple',
        'provider_subscription_id' => 'apple-otid-test',
        'product_id' => 'dodo.subscription.monthly',
        'plan' => 'app_monthly',
        'state' => 'active',
        'current_period_end' => now()->addMonth(),
    ]);

    $payload = [
        'notificationUUID' => 'notif-001',
        'notificationType' => 'EXPIRED',
        'signature' => 'STUB_VALID',
        'data' => ['original_transaction_id' => 'apple-otid-test'],
    ];

    $this->postJson('/api/iap/apple/notifications', $payload)->assertOk();
    // Second delivery (Apple retries):
    $this->postJson('/api/iap/apple/notifications', $payload)->assertOk();

    expect(IapWebhookEvent::where('event_id', 'notif-001')->count())->toBe(1);
    // First delivery transitioned to expired; second should be a no-op (still expired, not error)
    $sub = Subscription::where('provider_subscription_id', 'apple-otid-test')->first();
    expect($sub->state)->toBe('expired');
});

it('apple webhook rejects bad signature', function () {
    $this->postJson('/api/iap/apple/notifications', [
        'notificationUUID' => 'sig-bad',
        'notificationType' => 'DID_RENEW',
        'signature' => 'WRONG',
        'data' => [],
    ])->assertStatus(401);
});

it('google pubsub decodes base64 envelope', function () {
    $body = json_encode(['notificationType' => 'SUBSCRIPTION_RENEWED']) ?: '';
    $this->postJson('/api/iap/google/pubsub', [
        'message' => [
            'messageId' => 'pubsub-001',
            'data' => base64_encode($body),
        ],
    ])->assertOk();
});
