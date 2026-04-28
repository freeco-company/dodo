<?php

use App\Models\User;
use App\Services\Conversion\LifecycleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * ADR-003 §2.3 — `/api/bootstrap` lifecycle block 行為驗證。
 *
 * 規格：
 *   - lifecycle.status ∈ {visitor, registered, engaged, loyalist, applicant, franchisee}
 *   - show_franchise_cta = true  iff status ∈ {loyalist, applicant}
 *   - py-service 5xx / 連不到 → fallback visitor / cta=false
 *   - 公平交易法紅線：response body 全文不可含「下線 / 分潤 / 推薦獎金 / 招募 / 金字塔」
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
        ->assertJsonPath('lifecycle.franchise_url', 'https://js-store.com.tw/franchise/consult');
});

it('hides franchise CTA for visitor / registered / engaged stages', function (string $stage) {
    mockLifecycleResponse($stage);
    $user = makeUserWithUuid('uuid-'.$stage);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', $stage)
        ->assertJsonPath('lifecycle.show_franchise_cta', false);
})->with(['visitor', 'registered', 'engaged']);

it('shows franchise CTA for loyalist / applicant stages', function (string $stage) {
    mockLifecycleResponse($stage);
    $user = makeUserWithUuid('uuid-'.$stage);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', $stage)
        ->assertJsonPath('lifecycle.show_franchise_cta', true)
        ->assertJsonPath('lifecycle.franchise_url', 'https://js-store.com.tw/franchise/consult');
})->with(['loyalist', 'applicant']);

it('hides franchise CTA for franchisee (already converted, no need to push)', function () {
    mockLifecycleResponse('franchisee');
    $user = makeUserWithUuid('uuid-franchisee');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'franchisee')
        ->assertJsonPath('lifecycle.show_franchise_cta', false);
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
        ->assertJsonPath('lifecycle.show_franchise_cta', false);
});

it('falls back to visitor when py-service base_url is not configured', function () {
    config()->set('services.pandora_conversion.base_url', '');
    config()->set('services.pandora_conversion.shared_secret', '');
    $user = makeUserWithUuid('uuid-noconfig');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'visitor')
        ->assertJsonPath('lifecycle.show_franchise_cta', false);
});

it('falls back to visitor when py-service returns unknown stage', function () {
    mockLifecycleResponse('platinum_unicorn');
    $user = makeUserWithUuid('uuid-unknown');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/bootstrap')
        ->assertOk()
        ->assertJsonPath('lifecycle.status', 'visitor');
});

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

it('lifecycle response body must not contain MLM-flavored words (公平交易法 §21 紅線)', function () {
    mockLifecycleResponse('loyalist');
    $user = makeUserWithUuid('uuid-banwords');

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/bootstrap')->assertOk();
    $body = $response->getContent();

    // ADR-003 §6 / dodo CLAUDE.md 禁字。後端不該回吐這些詞，文案在前端 hardcode 中性詞。
    $banned = ['下線', '分潤', '推薦獎金', '招募', '金字塔', '老鼠會'];
    foreach ($banned as $word) {
        expect($body)->not->toContain($word, "response body 不可含禁字「{$word}」");
    }
});
