<?php

/**
 * Smoke + auth tests for the 17 endpoints added in the
 * frontend↔backend alignment PR. Each endpoint gets:
 *   - happy path (authenticated, expect a 2xx and key shape fields)
 *   - 401 unauth check
 *
 * Deliberately broad / shallow — deeper logic tests live next to the
 * service classes (e.g. EntitlementsService is covered separately).
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ----- /api/paywall -----
it('returns paywall view for authenticated user', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/paywall?trigger=manual_open')
        ->assertOk()
        ->assertJsonStructure([
            'variant_key',
            'content' => ['hero', 'tiers', 'trust_strip'],
            'user' => ['trial_state', 'trial_days_left', 'on_trial', 'subscription_type'],
            'trigger',
        ])
        ->assertJsonPath('trigger', 'manual_open');
});
it('rejects /paywall without auth', function () {
    $this->getJson('/api/paywall')->assertUnauthorized();
});

// ----- /api/rating-prompt -----
it('returns rating prompt decision', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/rating-prompt?trigger=app_open')
        ->assertOk()
        ->assertJsonStructure(['should_show', 'reason', 'trigger'])
        ->assertJsonPath('should_show', false);
});
it('rejects /rating-prompt without auth', function () {
    $this->getJson('/api/rating-prompt')->assertUnauthorized();
});

// ----- /api/pokedex -----
it('returns pokedex list', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/pokedex')
        ->assertOk()
        ->assertJsonStructure(['discoveries', 'total_discovered', 'shiny_count'])
        ->assertJsonPath('total_discovered', 0);
});
it('rejects /pokedex without auth', function () {
    $this->getJson('/api/pokedex')->assertUnauthorized();
});

// ----- /api/achievements -----
it('returns achievements with locked + unlocked split', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/achievements')
        ->assertOk()
        ->assertJsonStructure(['unlocked', 'locked']);
});
it('rejects /achievements without auth', function () {
    $this->getJson('/api/achievements')->assertUnauthorized();
});

// ----- /api/entitlements -----
it('returns entitlements snapshot', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/entitlements')
        ->assertOk()
        ->assertJsonStructure([
            'tier', 'subscription', 'unlimited_island',
            'island_quota_total', 'island_quota_used', 'island_quota_remaining',
        ]);
});
it('rejects /entitlements without auth', function () {
    $this->getJson('/api/entitlements')->assertUnauthorized();
});

// ----- /api/calendar -----
it('returns calendar heatmap with default 30 days', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/calendar')
        ->assertOk()
        ->assertJsonStructure([
            'days',
            'today',
            'stats' => ['total_days', 'perfect_days', 'logged_days', 'current_streak'],
        ])
        ->assertJsonPath('stats.total_days', 30);
});
it('respects ?days= query param', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/calendar?days=7')
        ->assertOk()
        ->assertJsonPath('stats.total_days', 7);
});
it('rejects /calendar without auth', function () {
    $this->getJson('/api/calendar')->assertUnauthorized();
});

// ----- /api/reports/weekly/{date} -----
it('returns weekly report stats', function () {
    $user = User::factory()->create();
    $today = date('Y-m-d');
    $this->actingAs($user, 'sanctum')
        ->getJson("/api/reports/weekly/{$today}")
        ->assertOk()
        ->assertJsonStructure([
            'week_start', 'week_end', 'avg_score',
            'daily_scores', 'daily_has_log', 'top_foods',
            'has_enough_data', 'letter',
        ]);
});
it('rejects bad date format on weekly report', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/reports/weekly/not-a-date')
        ->assertNotFound(); // route regex rejects
});
it('rejects /reports/weekly without auth', function () {
    $this->getJson('/api/reports/weekly/2026-04-28')->assertUnauthorized();
});

// ----- /api/suggest/next-meal -----
it('returns next meal suggestion', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/suggest/next-meal')
        ->assertOk()
        ->assertJsonStructure(['next_meal_type', 'message', 'food_suggestions']);
});
it('rejects /suggest/next-meal without auth', function () {
    $this->getJson('/api/suggest/next-meal')->assertUnauthorized();
});

// ----- /api/outfits + /api/outfits/equip -----
it('returns outfit catalog with equipped state', function () {
    $user = User::factory()->create([
        'outfits_owned' => ['none'],
        'equipped_outfit' => 'none',
    ]);
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/outfits')
        ->assertOk()
        ->assertJsonStructure(['outfits', 'equipped'])
        ->assertJsonPath('equipped', 'none');
});

it('equips an unlocked outfit', function () {
    $user = User::factory()->create([
        'outfits_owned' => ['none'],
        'equipped_outfit' => 'none',
        'level' => 50,
    ]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/outfits/equip', ['outfit_key' => 'scarf'])
        ->assertOk()
        ->assertJsonPath('equipped', 'scarf');
});

it('rejects equipping locked outfit', function () {
    $user = User::factory()->create([
        'outfits_owned' => ['none'],
        'equipped_outfit' => 'none',
        'level' => 1,
        'membership_tier' => 'free',
    ]);
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/outfits/equip', ['outfit_key' => 'fp_crown'])
        ->assertStatus(403);
});

it('rejects /outfits without auth', function () {
    $this->getJson('/api/outfits')->assertUnauthorized();
});

// ----- /api/island/* -----
it('returns island scenes', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/island/scenes')
        ->assertOk()
        ->assertJsonStructure(['tier', 'scenes']);
});

it('returns island store for known scene with frontend flat shape', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/island/store/seven_eleven')
        ->assertOk()
        ->assertJsonStructure([
            'key', 'name', 'emoji', 'backdrop', 'description',
            'npc' => ['emoji', 'name'],
            'dialog', 'intents',
            'user_state' => ['remaining_calories', 'protein_needed_g'],
            'unlocked', 'lock_reason', 'visit_count', 'entitlements',
            'scene',
        ]);
});

it('returns intents with prompt_line + recommendations + dodo dialog for seven_eleven', function () {
    $user = User::factory()->create(['avatar_animal' => 'rabbit']);
    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/island/store/seven_eleven')
        ->assertOk();

    // 朵朵 is the unified storekeeper across all scenes (group naming 2026-04-29).
    expect($resp->json('npc.name'))->toBe('朵朵');

    // 3-line opening dialog: pet → dodo → dodo (budget hint).
    $dialog = $resp->json('dialog');
    expect($dialog)->toHaveCount(3);
    expect($dialog[0]['speaker'])->toBe('mascot');
    expect($dialog[0]['text'])->toContain('兔兔');
    expect($dialog[1]['speaker'])->toBe('npc');
    expect($dialog[1]['text'])->toContain('朵朵');
    expect($dialog[2]['speaker'])->toBe('npc');
    expect($dialog[2]['text'])->toContain('朵朵');

    // Intents must carry prompt_line + recommendations so the rec panel
    // doesn't render '兔兔：undefined' on the frontend.
    $intents = (array) $resp->json('intents');
    expect(count($intents))->toBeGreaterThan(0);
    expect($intents[0])->toHaveKeys(['key', 'emoji', 'label', 'prompt_line', 'recommendations']);
    expect($intents[0]['prompt_line'])->toBeString();
    expect($intents[0]['prompt_line'])->toBeTruthy();
    $recs = (array) $intents[0]['recommendations'];
    expect(count($recs))->toBeGreaterThan(0);
    expect($recs[0])->toHaveKeys(['title', 'items', 'calories', 'protein_g', 'stars', 'why']);
});

it('returns 404 for unknown island scene', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/island/store/no-such-scene')
        ->assertNotFound();
});

it('consumes an island visit', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->postJson('/api/island/consume-visit', ['store_key' => 'seven_eleven'])
        ->assertOk()
        ->assertJsonStructure(['consumed', 'visit_count', 'entitlements']);
});

it('rejects /island/scenes without auth', function () {
    $this->getJson('/api/island/scenes')->assertUnauthorized();
});

// ----- /api/journey/milestone/{day} -----
it('returns journey milestone story', function () {
    $user = User::factory()->create(['avatar_animal' => 'cat']);
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/journey/milestone/7')
        ->assertOk()
        ->assertJsonStructure(['day', 'animal', 'lines'])
        ->assertJsonPath('day', 7)
        ->assertJsonPath('animal', 'cat');
});
it('rejects /journey/milestone without auth', function () {
    $this->getJson('/api/journey/milestone/3')->assertUnauthorized();
});

// ----- /api/cards/event-offer/next -----
it('returns has_offer:false when no active event offer', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/cards/event-offer/next')
        ->assertOk()
        ->assertJsonPath('has_offer', false);
});
it('rejects /cards/event-offer/next without auth', function () {
    $this->getJson('/api/cards/event-offer/next')->assertUnauthorized();
});

// ----- /api/meals/{meal}/correct -----
it('marks a meal as user-corrected', function () {
    $user = User::factory()->create();
    $meal = $user->meals()->create([
        'date' => date('Y-m-d'),
        'meal_type' => 'lunch',
        'food_name' => 'AI guess',
        'calories' => 500,
        'matched_food_ids' => [],
    ]);
    $this->actingAs($user, 'sanctum')
        ->putJson("/api/meals/{$meal->id}/correct", [
            'food_name' => '便當',
            'calories' => 700,
        ])
        ->assertOk();
    $fresh = $meal->fresh();
    expect($fresh->user_corrected)->toBeTrue();
    expect($fresh->food_name)->toBe('便當');
    expect($fresh->calories)->toBe(700);
});

it('rejects correcting another user meal', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $meal = $other->meals()->create([
        'date' => date('Y-m-d'),
        'meal_type' => 'lunch',
        'matched_food_ids' => [],
    ]);
    $this->actingAs($user, 'sanctum')
        ->putJson("/api/meals/{$meal->id}/correct", ['food_name' => 'sneaky'])
        ->assertStatus(403);
});

it('rejects empty correction body', function () {
    $user = User::factory()->create();
    $meal = $user->meals()->create([
        'date' => date('Y-m-d'),
        'meal_type' => 'lunch',
        'matched_food_ids' => [],
    ]);
    $this->actingAs($user, 'sanctum')
        ->putJson("/api/meals/{$meal->id}/correct", [])
        ->assertStatus(422);
});
