<?php

use App\Models\ProgressSnapshot;
use App\Models\RitualEvent;
use App\Models\ShareCardRender;
use App\Models\User;
use App\Services\Ritual\RitualDispatcher;
use App\Services\Ritual\ShareCardRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('RitualDispatcher fires once per idempotency_key', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-rit-1']);
    $svc = app(RitualDispatcher::class);

    $first = $svc->dispatch($user, RitualEvent::KEY_STREAK_MILESTONE, 'streak:30:'.$user->id, ['streak' => 30]);
    $second = $svc->dispatch($user, RitualEvent::KEY_STREAK_MILESTONE, 'streak:30:'.$user->id, ['streak' => 30]);

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    expect(RitualEvent::where('user_id', $user->id)->count())->toBe(1);
});

it('markSeen sets seen_at; only fires once', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-seen']);
    $svc = app(RitualDispatcher::class);
    $event = $svc->dispatch($user, RitualEvent::KEY_OUTFIT_UNLOCK_FULLSCREEN, 'outfit:42', ['outfit_id' => 42]);

    $svc->markSeen($event);
    $seenAt = $event->fresh()->seen_at;
    $svc->markSeen($event);

    expect($event->fresh()->seen_at?->toIso8601String())->toBe($seenAt?->toIso8601String());
});

it('ShareCardRenderer caches by checksum (same content → same row)', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-card']);
    $renderer = app(ShareCardRenderer::class);

    $a = $renderer->render($user, 'streak_milestone', 1, ['streak' => 30]);
    $b = $renderer->render($user, 'streak_milestone', 1, ['streak' => 30]);

    expect($a->id)->toBe($b->id);
    expect(ShareCardRender::count())->toBe(1);
});

it('ShareCardRenderer creates a new row when content changes', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-card-new']);
    $renderer = app(ShareCardRenderer::class);

    $renderer->render($user, 'streak_milestone', 1, ['streak' => 30]);
    $renderer->render($user, 'streak_milestone', 1, ['streak' => 60]);

    expect(ShareCardRender::count())->toBe(2);
});

it('GET /rituals/unread returns own unseen events only', function () {
    $alice = User::factory()->create(['pandora_user_uuid' => 'u-alice']);
    $bob = User::factory()->create(['pandora_user_uuid' => 'u-bob']);
    $svc = app(RitualDispatcher::class);

    $aliceUnseen = $svc->dispatch($alice, RitualEvent::KEY_STREAK_MILESTONE, 'a:30', ['streak' => 30]);
    $aliceSeen = $svc->dispatch($alice, RitualEvent::KEY_STREAK_MILESTONE, 'a:60', ['streak' => 60]);
    $svc->markSeen($aliceSeen);
    $svc->dispatch($bob, RitualEvent::KEY_STREAK_MILESTONE, 'b:30', ['streak' => 30]);

    $resp = $this->actingAs($alice, 'sanctum')
        ->getJson('/api/rituals/unread')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($resp->json('data.0.id'))->toBe($aliceUnseen->id);
});

it('POST /rituals/{e}/seen marks as seen', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-seen-ep']);
    $event = app(RitualDispatcher::class)->dispatch($user, RitualEvent::KEY_STREAK_MILESTONE, 'x:30', ['streak' => 30]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/rituals/{$event->id}/seen")
        ->assertOk();

    expect($event->fresh()->seen_at)->not->toBeNull();
});

it('POST /rituals/{e}/share returns image_url + marks shared', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-share']);
    $event = app(RitualDispatcher::class)->dispatch($user, RitualEvent::KEY_STREAK_MILESTONE, 'sh:30', ['streak' => 30]);

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson("/api/rituals/{$event->id}/share")
        ->assertOk();

    expect($resp->json('image_path'))->toContain("share-cards/{$user->id}/ritual_event_{$event->id}");
    expect($event->fresh()->shared_at)->not->toBeNull();
});

it('POST /rituals/{e}/seen forbids cross-tenant', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create(['pandora_user_uuid' => 'u-bob-x']);
    $bobEvent = app(RitualDispatcher::class)->dispatch($bob, RitualEvent::KEY_STREAK_MILESTONE, 'b:x', ['streak' => 30]);

    $this->actingAs($alice, 'sanctum')
        ->postJson("/api/rituals/{$bobEvent->id}/seen")
        ->assertForbidden();
});

it('POST /progress/compare/share-card validates ownership', function () {
    $alice = User::factory()->create(['pandora_user_uuid' => 'u-a-cmp']);
    $bob = User::factory()->create(['pandora_user_uuid' => 'u-b-cmp']);
    $aSnap = ProgressSnapshot::create(['user_id' => $alice->id, 'taken_at' => now()->subDays(60), 'weight_g' => 60000]);
    $bSnap = ProgressSnapshot::create(['user_id' => $bob->id, 'taken_at' => now()->subDays(30), 'weight_g' => 58000]);

    $this->actingAs($alice, 'sanctum')
        ->postJson('/api/progress/compare/share-card', [
            'snapshot_id_a' => $aSnap->id,
            'snapshot_id_b' => $bSnap->id,
        ])
        ->assertForbidden();
});

it('GET /collages returns user-owned collages list', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-list-cl']);
    \App\Models\MonthlyCollage::create([
        'user_id' => $user->id, 'month_start' => '2026-04-01',
        'snapshot_ids' => [1, 2, 3, 4, 5], 'stats_payload' => ['x' => 1],
        'narrative_letter' => '堅持的軌跡',
    ]);
    \App\Models\MonthlyCollage::create([
        'user_id' => $user->id, 'month_start' => '2026-03-01',
        'snapshot_ids' => [10, 11, 12, 13], 'stats_payload' => ['x' => 1],
        'narrative_letter' => '上上個月',
    ]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/collages')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($resp->json('data.0.month_start'))->toBe('2026-04-01');
});

it('GET /collages/{c} returns full detail with narrative_letter', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-show-cl']);
    $collage = \App\Models\MonthlyCollage::create([
        'user_id' => $user->id, 'month_start' => '2026-04-01',
        'snapshot_ids' => [1, 2, 3, 4, 5],
        'stats_payload' => ['food_days_logged' => 23, 'steps_total' => 245000],
        'narrative_letter' => '這個月妳堅持下來了 🌱',
    ]);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/collages/{$collage->id}")
        ->assertOk()
        ->assertJsonPath('narrative_letter', '這個月妳堅持下來了 🌱')
        ->assertJsonPath('stats.food_days_logged', 23);
});

it('GET /collages/{c} forbids cross-tenant', function () {
    $alice = User::factory()->create();
    $bob = User::factory()->create(['pandora_user_uuid' => 'u-bob-cl']);
    $bobCollage = \App\Models\MonthlyCollage::create([
        'user_id' => $bob->id, 'month_start' => '2026-04-01',
        'snapshot_ids' => [1, 2, 3, 4], 'stats_payload' => [],
        'narrative_letter' => 'x',
    ]);

    $this->actingAs($alice, 'sanctum')
        ->getJson("/api/collages/{$bobCollage->id}")
        ->assertForbidden();
});

it('POST /collages/{c}/share renders card + bumps shared_count', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-share-cl']);
    $collage = \App\Models\MonthlyCollage::create([
        'user_id' => $user->id, 'month_start' => '2026-04-01',
        'snapshot_ids' => [1, 2, 3, 4], 'stats_payload' => [],
        'narrative_letter' => 'x',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/collages/{$collage->id}/share")
        ->assertOk()
        ->assertJsonPath('shared_count', 1);

    expect($collage->fresh()->shared_count)->toBe(1);
});

it('POST /progress/compare/share-card returns image url for own snapshots', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-own-cmp']);
    $a = ProgressSnapshot::create(['user_id' => $user->id, 'taken_at' => now()->subDays(60), 'weight_g' => 60000]);
    $b = ProgressSnapshot::create(['user_id' => $user->id, 'taken_at' => now()->subDays(30), 'weight_g' => 58000]);

    $resp = $this->actingAs($user, 'sanctum')
        ->postJson('/api/progress/compare/share-card', [
            'snapshot_id_a' => $a->id,
            'snapshot_id_b' => $b->id,
        ])
        ->assertOk();

    expect($resp->json('image_path'))->toContain('share-cards');
    expect($resp->json('image_url'))->toStartWith('/storage/');
});
