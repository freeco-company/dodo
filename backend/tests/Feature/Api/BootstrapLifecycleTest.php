<?php

use App\Models\User;
use App\Services\Conversion\LifecycleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * ADR-008 §2.1 §2.3 — `/api/bootstrap` lifecycle block 行為驗證（取代 ADR-003 v1）。
 *
 * 規格：
 *   - lifecycle.status ∈ {visitor, loyalist, applicant, franchisee_self_use, franchisee_active}
 *   - show_franchise_cta = true  iff status ∈ {applicant, franchisee_self_use}
 *     （2026-05-02 紅線收緊：loyalist 移除，純 in-app 活躍 ≠ 加盟對象）
 *   - show_operator_portal = true iff status === 'franchisee_active'
 *   - py-service 5xx / 連不到 → fallback visitor / cta=false / portal=false
 *   - 公平交易法紅線（ADR-008 §7）：response body 全文不可含禁字
 */
beforeEach(function () {
    config()->set('services.pandora_conversion.base_url', 'http://py-service.test');
    config()->set('services.pandora_conversion.shared_secret', 'test-secret');
    config()->set('services.pandora_conversion.franchise_url', 'https://js-store.com.tw/franchise/consult');
    Cache::flush();
});

function makeUserWithUuid(string $uuid = 'uuid-test-001'): User
{
    return User::factory()->create([
        'pandora_user_uuid' => $uuid,
    ]);
}

function mockLifecycleResponse(string $stage): void
{
    Http::fake([
        '*/api/v1/users/*/lifecycle' => Http::response(['stage' => $stage], 200),
        // bootstrap also fires app.opened via ConversionEventPublisher with sync queue;
        // stub the events endpoint so the publisher's downstream HTTP call doesn't blow up.
        '*/api/v1/internal/events' => Http::response(['accepted' => true], 202),
    ]);
}

it('returns lifecycle block with default visitor for anonymous bootstrap', function () {
    $this->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'visitor')
        ->assertJsonPath('lifecycle.show_franchise_cta', false)
        ->assertJsonPath('lifecycle.show_operator_portal', false)
        ->assertJsonPath('lifecycle.franchise_url', 'https://js-store.com.tw/franchise/consult');
});

it('hides franchise CTA + operator portal for visitor stage', function () {
    mockLifecycleResponse('visitor');
    $user = makeUserWithUuid('uuid-visitor');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'visitor')
        ->assertJsonPath('lifecycle.show_franchise_cta', false)
        ->assertJsonPath('lifecycle.show_operator_portal', false);
});

it('shows franchise CTA for applicant / franchisee_self_use stages (2026-05-02 red line: loyalist removed)', function (string $stage) {
    mockLifecycleResponse($stage);
    $user = makeUserWithUuid('uuid-'.$stage);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', $stage)
        ->assertJsonPath('lifecycle.show_franchise_cta', true)
        ->assertJsonPath('lifecycle.show_operator_portal', false)
        ->assertJsonPath('lifecycle.franchise_url', 'https://js-store.com.tw/franchise/consult');
})->with(['applicant', 'franchisee_self_use']);

it('HIDES franchise CTA for loyalist (2026-05-02 red line: pure in-app activity ≠ franchise audience)', function () {
    mockLifecycleResponse('loyalist');
    $user = makeUserWithUuid('uuid-loyalist');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'loyalist')
        ->assertJsonPath('lifecycle.show_franchise_cta', false)
        ->assertJsonPath('lifecycle.show_operator_portal', false);
});

it('shows operator portal hook (and HIDES CTA banner) for franchisee_active', function () {
    mockLifecycleResponse('franchisee_active');
    $user = makeUserWithUuid('uuid-active');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'franchisee_active')
        // active operators 已經是進階經營者 — 不再露 CTA banner
        ->assertJsonPath('lifecycle.show_franchise_cta', false)
        // 改露段 2 「想擴大經營」入口
        ->assertJsonPath('lifecycle.show_operator_portal', true);
});

it('falls back to visitor when py-service returns 5xx', function () {
    Http::fake([
        '*/api/v1/users/*/lifecycle' => Http::response(['error' => 'boom'], 503),
        '*/api/v1/internal/events' => Http::response(['accepted' => true], 202),
    ]);
    $user = makeUserWithUuid('uuid-5xx');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'visitor')
        ->assertJsonPath('lifecycle.show_franchise_cta', false)
        ->assertJsonPath('lifecycle.show_operator_portal', false);
});

it('falls back to visitor when py-service base_url is not configured', function () {
    config()->set('services.pandora_conversion.base_url', '');
    config()->set('services.pandora_conversion.shared_secret', '');
    $user = makeUserWithUuid('uuid-noconfig');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'visitor')
        ->assertJsonPath('lifecycle.show_franchise_cta', false)
        ->assertJsonPath('lifecycle.show_operator_portal', false);
});

it('falls back to visitor when py-service returns unknown stage (e.g. legacy registered/engaged/franchisee)', function (string $legacy) {
    mockLifecycleResponse($legacy);
    $user = makeUserWithUuid('uuid-legacy-'.$legacy);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'visitor');
})->with(['platinum_unicorn', 'registered', 'engaged', 'franchisee']);

it('caches lifecycle status to avoid hammering py-service', function () {
    mockLifecycleResponse('loyalist');
    $user = makeUserWithUuid('uuid-cache');

    $this->actingAs($user, 'sanctum')->getJson('/api/bootstrap')->assertOk();
    $this->actingAs($user, 'sanctum')->getJson('/api/bootstrap')->assertOk();

    // 2 bootstrap calls; lifecycle endpoint should only be hit once (second cache).
    Http::assertSentCount(
        // 1 lifecycle + 2 events (app.opened fires every bootstrap, not cached)
        3,
    );
    $lifecycleCalls = 0;
    Http::recorded(function ($request) use (&$lifecycleCalls) {
        if (str_contains($request->url(), '/lifecycle')) {
            $lifecycleCalls++;
        }
    });
    expect($lifecycleCalls)->toBe(1);

    $cached = Cache::get(app(LifecycleClient::class)->cacheKey('uuid-cache'));
    expect($cached)->toBe('loyalist');
});

it('lifecycle response body must not contain MLM-flavored or vague-CTA words (公平交易法 §21 紅線, ADR-008 §7)', function () {
    mockLifecycleResponse('loyalist');
    $user = makeUserWithUuid('uuid-banwords');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/bootstrap')->assertOk();
    $body = $response->getContent();

    // ADR-003 §6 / ADR-008 §7 / dodo CLAUDE.md — UX sensitivity 禁字。
    // 後端絕不該回吐這些詞 — 文案在前端 hardcode 中性、平和詞。
    $banned = [
        // 公平交易法紅線（MLM 嫌疑）
        '下線', '分潤', '推薦獎金', '招募', '金字塔', '老鼠會',
        // ADR-008 §7 / UX 4 條 constraint — aggressive / 業務追殺感 / 過於曖昧
        '合作夥伴', '升級加盟', '升級加盟方案', '立刻', '馬上', '快速', '機會難得',
    ];
    foreach ($banned as $word) {
        expect($body)->not->toContain($word, "response body 不可含禁字「{$word}」");
    }
});

it('LifecycleClient::stages() returns the canonical 5-stage ADR-008 list', function () {
    expect(LifecycleClient::stages())->toBe([
        'visitor',
        'loyalist',
        'applicant',
        'franchisee_self_use',
        'franchisee_active',
    ]);
});
