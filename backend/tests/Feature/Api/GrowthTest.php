<?php

use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns weight timeseries densified for 30 days', function () {
    $user = User::factory()->create();

    DailyLog::create(['user_id' => $user->id, 'date' => today()->subDays(5), 'weight_kg' => 60.5]);
    DailyLog::create(['user_id' => $user->id, 'date' => today()->subDays(2), 'weight_kg' => 60.2]);
    DailyLog::create(['user_id' => $user->id, 'date' => today(), 'weight_kg' => 60.7]);

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/me/growth/timeseries?metric=weight_kg&days=30')
        ->assertOk()
        ->assertJsonPath('metric', 'weight_kg')
        ->assertJsonPath('days', 30);

    $points = $resp->json('points');
    expect($points)->toHaveCount(30);
    expect(end($points)['value'])->toEqualWithDelta(60.7, 0.001);
});

it('falls back to weight_kg when metric unsupported', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/me/growth/timeseries?metric=junk&days=7')
        ->assertOk()
        ->assertJsonPath('metric', 'weight_kg');
});

it('caps days at 365', function () {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum')
        ->getJson('/api/me/growth/timeseries?metric=weight_kg&days=9999')
        ->assertStatus(422);
});

it('returns weekly review with current vs previous + dodo commentary', function () {
    $user = User::factory()->create();

    foreach (range(0, 6) as $i) {
        DailyLog::create([
            'user_id' => $user->id,
            'date' => today()->subDays($i),
            'weight_kg' => 60 + ($i * 0.1),
            'total_calories' => 1500,
            'total_protein_g' => 70,
            'meals_logged' => 3,
        ]);
    }

    foreach (range(7, 13) as $i) {
        DailyLog::create([
            'user_id' => $user->id,
            'date' => today()->subDays($i),
            'weight_kg' => 61,
            'total_calories' => 1700,
            'total_protein_g' => 60,
            'meals_logged' => 3,
        ]);
    }

    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/me/growth/weekly-review')
        ->assertOk();

    $resp->assertJsonStructure([
        'window' => ['start', 'end'],
        'previous_window' => ['start', 'end'],
        'current' => ['days_logged', 'avg_calories', 'avg_protein_g', 'weight_start', 'weight_end'],
        'previous' => ['days_logged', 'avg_calories'],
        'deltas' => ['avg_calories', 'avg_protein_g', 'weight_change_kg'],
        'dodo_commentary' => ['headline', 'lines'],
    ]);

    expect($resp->json('current.days_logged'))->toBe(7);
    expect($resp->json('dodo_commentary.lines'))->toBeArray()->not->toBeEmpty();
});

it('weekly review handles empty user gracefully with starter commentary', function () {
    $user = User::factory()->create();
    $resp = $this->actingAs($user, 'sanctum')
        ->getJson('/api/me/growth/weekly-review')
        ->assertOk();

    expect($resp->json('current.days_logged'))->toBe(0);
    expect($resp->json('dodo_commentary.headline'))->toContain('還沒');
});

it('growth endpoints require auth', function () {
    $this->getJson('/api/me/growth/timeseries')->assertStatus(401);
    $this->getJson('/api/me/growth/weekly-review')->assertStatus(401);
});
