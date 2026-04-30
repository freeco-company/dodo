<?php

use App\Models\KnowledgeArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeArticle(array $overrides = []): KnowledgeArticle
{
    return KnowledgeArticle::create(array_merge([
        'slug' => 'test-' . uniqid(),
        'title' => 'Test Article',
        'category' => 'protein',
        'tags' => ['test'],
        'audience' => ['retail'],
        'body' => 'plain body',
        'dodo_voice_body' => '朵朵 voice body',
        'published_at' => now()->subHour(),
    ], $overrides));
}

it('lists published articles for retail audience', function () {
    $user = User::factory()->create();
    makeArticle(['title' => 'A1', 'audience' => ['retail']]);
    makeArticle(['title' => 'A2', 'audience' => ['retail', 'franchisee']]);
    makeArticle(['title' => 'Hidden', 'audience' => ['franchisee']]);
    makeArticle(['title' => 'Draft', 'published_at' => null]);

    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/knowledge?audience=retail');
    expect($resp->json('count'))->toBe(2);
});

it('filters by category', function () {
    $user = User::factory()->create();
    makeArticle(['category' => 'protein']);
    makeArticle(['category' => 'fiber']);

    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/knowledge?category=protein');
    expect($resp->json('count'))->toBe(1);
});

it('daily pick is deterministic per user per day', function () {
    $user = User::factory()->create();
    foreach (range(1, 5) as $i) {
        makeArticle(['title' => "T{$i}"]);
    }

    $r1 = $this->actingAs($user, 'sanctum')->getJson('/api/knowledge/daily');
    $r2 = $this->actingAs($user, 'sanctum')->getJson('/api/knowledge/daily');
    expect($r1->json('article.slug'))->toBe($r2->json('article.slug'));
});

it('show endpoint returns full body and increments view count', function () {
    $user = User::factory()->create();
    $a = makeArticle(['slug' => 'show-me', 'view_count' => 5]);

    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/knowledge/show-me');
    expect($resp->json('article.body'))->toBe('朵朵 voice body');

    $a->refresh();
    expect($a->view_count)->toBe(6);
});

it('show 404 for missing or unpublished', function () {
    $user = User::factory()->create();
    makeArticle(['slug' => 'draft', 'published_at' => null]);

    $this->actingAs($user, 'sanctum')->getJson('/api/knowledge/draft')->assertStatus(404);
    $this->actingAs($user, 'sanctum')->getJson('/api/knowledge/missing')->assertStatus(404);
});

it('save endpoint increments saved count', function () {
    $user = User::factory()->create();
    $a = makeArticle(['slug' => 'save-me']);

    $this->actingAs($user, 'sanctum')->postJson('/api/knowledge/save-me/save')->assertOk();

    $a->refresh();
    expect($a->saved_count)->toBe(1);
});

it('all knowledge endpoints require auth', function () {
    $this->getJson('/api/knowledge')->assertStatus(401);
    $this->getJson('/api/knowledge/daily')->assertStatus(401);
    $this->getJson('/api/knowledge/foo')->assertStatus(401);
    $this->postJson('/api/knowledge/foo/save')->assertStatus(401);
});

it('returns null article for empty kb', function () {
    $user = User::factory()->create();
    $resp = $this->actingAs($user, 'sanctum')->getJson('/api/knowledge/daily');
    expect($resp->json('article'))->toBeNull();
    expect($resp->json('reason'))->toBe('empty_kb');
});
