<?php

use App\Jobs\EvaluateInsightsForUserJob;
use App\Models\HealthMetric;
use App\Models\Meal;
use App\Models\User;
use App\Services\HealthMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('insights:evaluate-daily skips users with no recent activity', function () {
    User::factory()->create();
    $exit = $this->artisan('insights:evaluate-daily');
    $exit->assertSuccessful();
});

it('insights:evaluate-daily evaluates only active users (≥1 record in past 7d)', function () {
    $now = CarbonImmutable::now('Asia/Taipei');
    $active = User::factory()->create(['pandora_user_uuid' => 'u-active']);
    $dormant = User::factory()->create(['pandora_user_uuid' => 'u-dormant']);

    Meal::create([
        'user_id' => $active->id,
        'pandora_user_uuid' => $active->pandora_user_uuid,
        'date' => $now->subDays(2)->toDateString(),
        'meal_type' => 'lunch', 'food_name' => 'x',
        'recognized_via' => 'manual',
        'calories' => 600, 'carbs_g' => 60, 'protein_g' => 30, 'fat_g' => 15,
    ]);

    $this->artisan('insights:evaluate-daily')->assertSuccessful();
});

it('insights:evaluate-daily --user=ID evaluates a single user', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-single']);
    $this->artisan('insights:evaluate-daily', ['--user' => $user->id])->assertSuccessful();
});

it('MealController::store dispatches EvaluateInsightsForUserJob after commit', function () {
    Bus::fake();
    $user = User::factory()->create(['pandora_user_uuid' => 'u-realtime-meal']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/meals', [
            'date' => now()->toDateString(),
            'meal_type' => 'lunch',
            'food_name' => 'x',
            'calories' => 600, 'carbs_g' => 60, 'protein_g' => 30, 'fat_g' => 15,
        ])
        ->assertCreated();

    Bus::assertDispatched(EvaluateInsightsForUserJob::class, function ($job) use ($user) {
        return $job->userId === $user->id;
    });
});

it('HealthMetricsController::sync dispatches EvaluateInsightsForUserJob after commit', function () {
    Bus::fake();
    $user = User::factory()->create(['pandora_user_uuid' => 'u-realtime-health']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/health/sync', [
            'metrics' => [[
                'type' => HealthMetricsService::TYPE_STEPS,
                'value' => 8000, 'unit' => 'steps',
                'recorded_at' => now()->toIso8601String(),
            ]],
        ])
        ->assertOk();

    Bus::assertDispatched(EvaluateInsightsForUserJob::class, function ($job) use ($user) {
        return $job->userId === $user->id;
    });
});

it('EvaluateInsightsForUserJob silently no-ops when user is gone', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-deleted']);
    $userId = $user->id;
    $user->delete();

    expect(fn () => app(EvaluateInsightsForUserJob::class, ['userId' => $userId])
        ->handle(app(\App\Services\Insight\InsightEngine::class)))
        ->not->toThrow(\Throwable::class);
});
