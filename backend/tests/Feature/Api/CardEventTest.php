<?php

use App\Models\CardEventOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('db:seed', ['--force' => true]);
});

function makeUser(string $uuid): User
{
    return User::factory()->create([
        'pandora_user_uuid' => $uuid,
        'current_streak' => 0,
        'level' => 5,
    ]);
}

function makeOffer(User $user, string $cardId = 'e001', string $status = 'pending'): CardEventOffer
{
    return CardEventOffer::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'card_id' => $cardId,
        'offered_at' => now(),
        'expires_at' => now()->addMinutes(45),
        'status' => $status,
    ]);
}

// ---------------- event-offer (GET) ----------------

it('event-offer requires sanctum auth', function () {
    $this->getJson('/api/cards/event-offer/1')->assertStatus(401);
});

it('event-offer returns offer detail for owner', function () {
    $user = makeUser('00000000-0000-0000-0000-0000000000a1');
    $offer = makeOffer($user);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/cards/event-offer/{$offer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $offer->id)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.card.id', 'e001');
});

it('event-offer 404s for another tenant', function () {
    $owner = makeUser('00000000-0000-0000-0000-0000000000b1');
    $intruder = makeUser('00000000-0000-0000-0000-0000000000b2');
    $offer = makeOffer($owner);

    $this->actingAs($intruder, 'sanctum')
        ->getJson("/api/cards/event-offer/{$offer->id}")
        ->assertStatus(404);
});

it('event-offer 404s for missing id', function () {
    $user = makeUser('00000000-0000-0000-0000-0000000000c1');
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/cards/event-offer/999999')
        ->assertStatus(404);
});

// ---------------- event-skip ----------------

it('event-skip requires auth', function () {
    $this->postJson('/api/cards/event-skip', ['offer_id' => 1])->assertStatus(401);
});

it('event-skip flips status to skipped', function () {
    $user = makeUser('00000000-0000-0000-0000-0000000000d1');
    $offer = makeOffer($user);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/event-skip', ['offer_id' => $offer->id])
        ->assertOk()
        ->assertJsonPath('skipped', true);

    expect($offer->fresh()->status)->toBe('skipped');
});

it('event-skip is idempotent on already-skipped offer', function () {
    $user = makeUser('00000000-0000-0000-0000-0000000000d2');
    $offer = makeOffer($user, status: 'skipped');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/event-skip', ['offer_id' => $offer->id])
        ->assertOk();

    expect($offer->fresh()->status)->toBe('skipped');
});

it('event-skip 404s when offer belongs to another tenant', function () {
    $owner = makeUser('00000000-0000-0000-0000-0000000000d3');
    $intruder = makeUser('00000000-0000-0000-0000-0000000000d4');
    $offer = makeOffer($owner);

    $this->actingAs($intruder, 'sanctum')
        ->postJson('/api/cards/event-skip', ['offer_id' => $offer->id])
        ->assertStatus(404);

    expect($offer->fresh()->status)->toBe('pending');
});

// ---------------- event-draw ----------------

it('event-draw requires auth', function () {
    $this->postJson('/api/cards/event-draw', ['offer_id' => 1])->assertStatus(401);
});

it('event-draw creates a play row and stamps offer with play_id', function () {
    $user = makeUser('00000000-0000-0000-0000-0000000000e1');
    $offer = makeOffer($user);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/event-draw', ['offer_id' => $offer->id])
        ->assertOk()
        ->assertJsonStructure(['play_id', 'id', 'choices', 'stamina', 'offer_id']);

    expect($response->json('id'))->toBe('e001');
    expect($offer->fresh()->play_id)->toBe($response->json('play_id'));
});

it('event-draw rejects an offer already answered/skipped', function () {
    $user = makeUser('00000000-0000-0000-0000-0000000000e2');
    $offer = makeOffer($user, status: 'answered');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/event-draw', ['offer_id' => $offer->id])
        ->assertStatus(409);
});

it('event-draw 404s for another tenant', function () {
    $owner = makeUser('00000000-0000-0000-0000-0000000000e3');
    $intruder = makeUser('00000000-0000-0000-0000-0000000000e4');
    $offer = makeOffer($owner);

    $this->actingAs($intruder, 'sanctum')
        ->postJson('/api/cards/event-draw', ['offer_id' => $offer->id])
        ->assertStatus(404);
});

it('event-draw marks expired offer missed and returns 409', function () {
    $user = makeUser('00000000-0000-0000-0000-0000000000e5');
    $offer = CardEventOffer::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'card_id' => 'e001',
        'offered_at' => now()->subHours(2),
        'expires_at' => now()->subHour(),
        'status' => 'pending',
    ]);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/cards/event-draw', ['offer_id' => $offer->id])
        ->assertStatus(409);

    expect($offer->fresh()->status)->toBe('missed');
});
