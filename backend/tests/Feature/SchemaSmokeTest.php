<?php

use App\Models\Achievement;
use App\Models\CardEventOffer;
use App\Models\CardPlay;
use App\Models\Conversation;
use App\Models\DailyLog;
use App\Models\DailyQuest;
use App\Models\Food;
use App\Models\FoodDiscovery;
use App\Models\JourneyAdvance;
use App\Models\Meal;
use App\Models\StoreVisit;
use App\Models\UsageLog;
use App\Models\User;
use App\Models\UserSummary;
use App\Models\WeeklyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates all 15 doudou tables on migrate', function () {
    $tables = [
        'users', 'food_database', 'daily_logs', 'meals', 'conversations',
        'user_summaries', 'weekly_reports', 'achievements', 'food_discoveries',
        'usage_logs', 'card_plays', 'card_event_offers', 'daily_quests',
        'store_visits', 'journey_advances',
    ];
    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Missing table: {$table}");
    }
});

it('has the wide users table with doudou columns', function () {
    $columns = ['legacy_id', 'line_id', 'apple_id', 'avatar_color', 'avatar_species',
        'level', 'xp', 'membership_tier', 'subscription_type', 'journey_cycle', 'journey_day'];
    foreach ($columns as $col) {
        expect(Schema::hasColumn('users', $col))->toBeTrue("Missing column users.{$col}");
    }
});

it('creates a user via factory with json casts', function () {
    $user = User::factory()->create();
    expect($user->id)->toBeInt()
        ->and($user->allergies)->toBeArray()
        ->and($user->outfits_owned)->toBeArray()
        ->and($user->level)->toBeGreaterThanOrEqual(1);
});

it('cascades on user delete', function () {
    $user = User::factory()->create();
    DailyLog::factory()->for($user)->create();
    Meal::factory()->for($user)->create();
    Conversation::factory()->for($user)->create();
    Achievement::factory()->for($user)->create();

    $user->delete();

    expect(DailyLog::where('user_id', $user->id)->count())->toBe(0)
        ->and(Meal::where('user_id', $user->id)->count())->toBe(0)
        ->and(Conversation::where('user_id', $user->id)->count())->toBe(0)
        ->and(Achievement::where('user_id', $user->id)->count())->toBe(0);
});

it('enforces unique constraint on daily_logs (user_id, date)', function () {
    $user = User::factory()->create();
    DailyLog::factory()->for($user)->create(['date' => '2026-04-28']);

    expect(fn () => DailyLog::factory()->for($user)->create(['date' => '2026-04-28']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('enforces unique constraint on achievements (user_id, achievement_key)', function () {
    $user = User::factory()->create();
    Achievement::factory()->for($user)->create(['achievement_key' => 'streak_7', 'achievement_name' => '連續 7']);

    expect(fn () => Achievement::factory()->for($user)->create(['achievement_key' => 'streak_7', 'achievement_name' => '連續 7']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('persists meal with json casts and decimal precision', function () {
    $user = User::factory()->create();
    $meal = Meal::factory()->for($user)->create([
        'food_components' => ['rice' => 200, 'chicken' => 150],
        'protein_g' => 32.5,
    ]);
    $meal->refresh();
    expect($meal->food_components)->toBe(['rice' => 200, 'chicken' => 150])
        ->and((float) $meal->protein_g)->toBe(32.5);
});

it('food_database table maps to Food model', function () {
    $food = Food::factory()->create(['name_zh' => '雞胸肉']);
    expect($food->getTable())->toBe('food_database')
        ->and(Food::where('name_zh', '雞胸肉')->exists())->toBeTrue();
});

it('user has all hasMany relationships defined', function () {
    $user = User::factory()->create();
    foreach ([
        'dailyLogs', 'meals', 'conversations', 'weeklyReports', 'achievements',
        'foodDiscoveries', 'usageLogs', 'cardPlays', 'cardEventOffers',
        'dailyQuests', 'storeVisits', 'journeyAdvances',
    ] as $rel) {
        expect($user->{$rel}())->not->toBeNull("Missing relation: {$rel}");
    }
});
