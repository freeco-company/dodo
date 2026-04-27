<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('seeds the eight content keys into app_config', function () {
    Artisan::call('db:seed', ['--force' => true]);

    $keys = DB::table('app_config')->pluck('key')->all();

    // The eight files copied from ai-game/data/.
    $expected = [
        'chat_intents',
        'island_scenes',
        'journey_story',
        'knowledge_decks',
        'mascot_voices',
        'npc_dialogs',
        'question_decks',
        'store_intents',
    ];

    foreach ($expected as $k) {
        expect($keys)->toContain($k);
    }
    expect(count($keys))->toBeGreaterThanOrEqual(8);
});

it('seeds the question_decks payload with at least 30 cards', function () {
    Artisan::call('db:seed', ['--force' => true]);

    $row = DB::table('app_config')->where('key', 'question_decks')->first();
    expect($row)->not->toBeNull();

    $value = json_decode((string) $row->value, true);
    expect($value)->toBeArray();
    expect($value['cards'] ?? null)->toBeArray();
    expect(count($value['cards']))->toBeGreaterThanOrEqual(30);
});
