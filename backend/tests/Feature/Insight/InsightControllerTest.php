<?php

use App\Models\Insight;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeInsight(User $user, array $overrides = []): Insight
{
    return Insight::create(array_merge([
        'user_id' => $user->id,
        'insight_key' => 'weight_plateau_detected',
        'idempotency_key' => 'u:'.$user->id.':wpd:'.uniqid(),
        'detection_payload' => ['delta_kg' => 0.05],
        'narrative_headline' => '妳的體重 5 天平台了 🌱',
        'narrative_body' => '不是停滯，是身體在適應',
        'action_suggestion' => [['label' => 'try', 'action_key' => 'x']],
        'source' => 'rule_engine',
        'fired_at' => now(),
    ], $overrides));
}

it('GET /insights/unread returns only unread + own', function () {
    $alice = User::factory()->create(['pandora_user_uuid' => 'u-a']);
    $bob = User::factory()->create(['pandora_user_uuid' => 'u-b']);
    makeInsight($alice);
    makeInsight($alice, ['read_at' => now()]);   // already read
    makeInsight($alice, ['dismissed_at' => now()]); // dismissed
    makeInsight($bob);                            // not alice's

    $this->actingAs($alice, 'sanctum')
        ->getJson('/api/insights/unread')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('POST /insights/{i}/read marks as read', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-r']);
    $i = makeInsight($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/insights/{$i->id}/read")
        ->assertOk();

    expect($i->fresh()->read_at)->not->toBeNull();
});

it('POST /insights/{i}/dismiss marks as dismissed', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-d']);
    $i = makeInsight($user);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/insights/{$i->id}/dismiss")
        ->assertOk();

    expect($i->fresh()->dismissed_at)->not->toBeNull();
});

it('GET /insights/{i} forbids cross-tenant', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create();
    $i = makeInsight($bob);

    $this->actingAs($alice, 'sanctum')
        ->getJson("/api/insights/{$i->id}")
        ->assertForbidden();
});

it('GET /insights/history returns paginated own', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-h']);
    for ($i = 0; $i < 5; $i++) {
        makeInsight($user);
    }

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/insights/history?limit=3')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('endpoints all require authentication', function () {
    $user = User::factory()->create();
    $i = makeInsight($user);

    $this->getJson('/api/insights/unread')->assertUnauthorized();
    $this->getJson('/api/insights/history')->assertUnauthorized();
    $this->getJson("/api/insights/{$i->id}")->assertUnauthorized();
    $this->postJson("/api/insights/{$i->id}/read")->assertUnauthorized();
    $this->postJson("/api/insights/{$i->id}/dismiss")->assertUnauthorized();
});
