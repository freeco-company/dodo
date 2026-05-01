<?php

// 集團合規硬規則（docs/group-fp-product-compliance.md）：
// 凡 type=fp_recipe / category=fp_recipe 的題卡，當
// services.fp_product_content.enabled=false（預設）時必須在 query 層即被過濾，
// 不可下發到 /api/cards/draw 任何 client-facing 路徑。

use App\Models\CardPlay;
use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('never draws an fp_recipe card when flag is off (default)', function () {
    Artisan::call('db:seed', ['--force' => true]);
    config(['services.fp_product_content.enabled' => false]);

    $user = User::factory()->create(['current_streak' => 0, 'membership_tier' => 'fp_franchise']);

    for ($i = 0; $i < 30; $i++) {
        $user->refresh();
        $payload = $this->actingAs($user, 'sanctum')->postJson('/api/cards/draw');
        if ($payload->status() === 409) {
            $user->update(['current_streak' => 99]);
            DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)->delete();
            CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)->delete();

            continue;
        }
        $payload->assertOk();
        $body = $payload->json();
        expect($body['type'])->not->toBe('fp_recipe');
        expect($body['category'])->not->toBe('fp_recipe');
        expect($body['id'] ?? '')->not->toStartWith('fp-');
    }
});

it('exposes fp_recipe cards when flag is on', function () {
    Artisan::call('db:seed', ['--force' => true]);
    config(['services.fp_product_content.enabled' => true]);

    // is_franchisee=true required by CardService FP gate (in addition to compliance flag).
    $user = User::factory()->create(['current_streak' => 0, 'membership_tier' => 'fp_franchise', 'is_franchisee' => true]);

    $sawFp = false;
    for ($i = 0; $i < 60; $i++) {
        $user->refresh();
        $resp = $this->actingAs($user, 'sanctum')->postJson('/api/cards/draw');
        if ($resp->status() === 409) {
            $user->update(['current_streak' => 99]);
            DailyLog::where('pandora_user_uuid', $user->pandora_user_uuid)->delete();
            CardPlay::where('pandora_user_uuid', $user->pandora_user_uuid)->delete();

            continue;
        }
        $resp->assertOk();
        $body = $resp->json();
        if (($body['type'] ?? null) === 'fp_recipe' || ($body['category'] ?? null) === 'fp_recipe') {
            $sawFp = true;

            break;
        }
    }
    expect($sawFp)->toBeTrue('Expected to see at least one fp_recipe card across 60 draws when flag is on');
});

it('omits fp_recipe cards from collection totals when flag is off', function () {
    Artisan::call('db:seed', ['--force' => true]);
    config(['services.fp_product_content.enabled' => false]);

    $user = User::factory()->create(['membership_tier' => 'fp_franchise']);
    $payload = $this->actingAs($user, 'sanctum')
        ->getJson('/api/cards/collection')
        ->assertOk()
        ->json();

    foreach ($payload['locked_cards'] ?? [] as $card) {
        expect($card['type'] ?? null)->not->toBe('fp_recipe');
        expect($card['id'] ?? '')->not->toStartWith('fp-');
    }
});
