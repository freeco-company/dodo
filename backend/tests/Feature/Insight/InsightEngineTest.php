<?php

use App\Models\FastingSession;
use App\Models\HealthMetric;
use App\Models\Insight;
use App\Models\InsightRuleRun;
use App\Models\Meal;
use App\Models\User;
use App\Services\HealthMetricsService;
use App\Services\Insight\InsightEngine;
use App\Services\Insight\Rules\StreakMilestone30Rule;
use App\Services\Insight\Rules\WeightPlateauRule;
use App\Services\Insight\UserDataAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * SPEC-cross-metric-insight-v1 PR #1 — engine + 4 core rules + aggregator.
 *
 * Each test is hermetic: seeds the exact rows needed for one rule, evaluates
 * at a fixed `now`, asserts insight fired with expected detection payload.
 */
function seedWeight(User $user, CarbonImmutable $date, float $kg): void
{
    HealthMetric::create([
        'user_id' => $user->id,
        'type' => HealthMetricsService::TYPE_WEIGHT,
        'value' => $kg,
        'unit' => 'kg',
        'recorded_at' => $date,
        'source' => 'test',
    ]);
}

function seedMeal(User $user, CarbonImmutable $date, int $kcal, float $protein = 30): void
{
    Meal::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'date' => $date->toDateString(),
        'meal_type' => 'lunch',
        'food_name' => 'x',
        'recognized_via' => 'manual',
        'calories' => $kcal,
        'carbs_g' => 50, 'protein_g' => $protein, 'fat_g' => 15,
    ]);
}

function seedSleep(User $user, CarbonImmutable $date, int $minutes): void
{
    HealthMetric::create([
        'user_id' => $user->id,
        'type' => HealthMetricsService::TYPE_SLEEP_MINUTES,
        'value' => $minutes,
        'unit' => 'min',
        'recorded_at' => $date,
        'source' => 'test',
    ]);
}

function seedSteps(User $user, CarbonImmutable $date, int $steps): void
{
    HealthMetric::create([
        'user_id' => $user->id,
        'type' => HealthMetricsService::TYPE_STEPS,
        'value' => $steps,
        'unit' => 'steps',
        'recorded_at' => $date,
        'source' => 'test',
    ]);
}

it('WeightPlateauRule fires when 7d MA flat + kcal SD < 10%', function () {
    $now = CarbonImmutable::parse('2026-05-04 09:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-plateau']);

    // current week: weight ≈ 56.0kg, prev week: weight ≈ 56.05kg → delta < 0.2
    foreach (range(0, 6) as $i) {
        seedWeight($user, $now->subDays($i), 56.0 + ($i % 2 === 0 ? 0.1 : -0.1));
        seedWeight($user, $now->subDays(7 + $i), 56.05 + ($i % 2 === 0 ? 0.1 : -0.1));
    }
    // current week meals: stable kcal (1500 ± 50), SD ratio < 10%
    foreach (range(0, 6) as $i) {
        seedMeal($user, $now->subDays($i), 1500 + ($i % 2 === 0 ? 30 : -30));
    }

    $insights = app(InsightEngine::class)->evaluateAllForUser($user, $now);
    $keys = collect($insights)->pluck('insight_key')->all();

    expect($keys)->toContain(WeightPlateauRule::KEY);
});

it('WeightPlateauRule does NOT fire when weight dropping > 0.2kg', function () {
    $now = CarbonImmutable::parse('2026-05-04 09:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-dropping']);

    foreach (range(0, 6) as $i) {
        seedWeight($user, $now->subDays($i), 55.0);          // current avg 55.0
        seedWeight($user, $now->subDays(7 + $i), 56.0);      // prev avg 56.0 → delta 1.0kg
        seedMeal($user, $now->subDays($i), 1500);
    }

    $insights = app(InsightEngine::class)->evaluateAllForUser($user, $now);
    $keys = collect($insights)->pluck('insight_key')->all();

    expect($keys)->not->toContain(WeightPlateauRule::KEY);
});

it('SleepDeficitWithWeightStallRule fires when sleep < 6h + weight not dropping', function () {
    $now = CarbonImmutable::parse('2026-05-04 09:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-sleep-stall']);

    foreach (range(0, 6) as $i) {
        seedWeight($user, $now->subDays($i), 56.0);
        seedWeight($user, $now->subDays(7 + $i), 56.0);
        seedSleep($user, $now->subDays($i), 300); // 5h
        seedMeal($user, $now->subDays($i), 1500);
    }

    $insights = app(InsightEngine::class)->evaluateAllForUser($user, $now);
    $keys = collect($insights)->pluck('insight_key')->all();

    expect($keys)->toContain('sleep_deficit_with_weight_stall');
});

it('FastingStreakWithStepsDropRule fires when 7-day streak + steps drop 30%+', function () {
    $now = CarbonImmutable::parse('2026-05-04 09:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-fast-step']);

    // 8-day fasting streak (use early-day time so UTC↔Asia/Taipei stays on same date)
    foreach (range(0, 7) as $i) {
        FastingSession::create([
            'user_id' => $user->id,
            'mode' => '16_8',
            'target_duration_minutes' => 960,
            'started_at' => $now->subDays($i)->setTime(8, 0),
            'ended_at' => $now->subDays($i)->setTime(16, 0),
            'completed' => true,
            'source_app' => 'dodo',
        ]);
    }
    // current week steps: 30k; prev week: 50k → drop 40%
    foreach (range(0, 6) as $i) {
        seedSteps($user, $now->subDays($i), 4286);            // ~30k/7d
        seedSteps($user, $now->subDays(7 + $i), 7143);        // ~50k/7d
    }

    $insights = app(InsightEngine::class)->evaluateAllForUser($user, $now);
    $keys = collect($insights)->pluck('insight_key')->all();

    expect($keys)->toContain('fasting_streak_with_steps_drop');
});

it('StreakMilestone30Rule fires when meal streak hits 30', function () {
    $now = CarbonImmutable::parse('2026-05-04 09:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-30streak']);

    foreach (range(0, 29) as $i) {
        seedMeal($user, $now->subDays($i), 1500);
    }

    $insights = app(InsightEngine::class)->evaluateAllForUser($user, $now);
    $hits = collect($insights)->pluck('insight_key')->all();

    expect($hits)->toContain(StreakMilestone30Rule::KEY);
    $milestone = collect($insights)->firstWhere('insight_key', StreakMilestone30Rule::KEY);
    expect($milestone->detection_payload['streak_days'])->toBe(30);
});

it('StreakMilestone30Rule does NOT fire on streak day 31 (only round numbers)', function () {
    $now = CarbonImmutable::parse('2026-05-04 09:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-31streak']);

    foreach (range(0, 30) as $i) {
        seedMeal($user, $now->subDays($i), 1500);
    }

    $insights = app(InsightEngine::class)->evaluateAllForUser($user, $now);
    $keys = collect($insights)->pluck('insight_key')->all();

    expect($keys)->not->toContain(StreakMilestone30Rule::KEY);
});

it('engine is idempotent within cooldown — second run same week does not duplicate', function () {
    $now = CarbonImmutable::parse('2026-05-04 09:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-idempotent']);

    foreach (range(0, 6) as $i) {
        seedWeight($user, $now->subDays($i), 56.0);
        seedWeight($user, $now->subDays(7 + $i), 56.0);
        seedMeal($user, $now->subDays($i), 1500);
    }

    $first = app(InsightEngine::class)->evaluateAllForUser($user, $now);
    $second = app(InsightEngine::class)->evaluateAllForUser($user, $now->addHours(2));

    expect(count($first))->toBeGreaterThan(0);
    expect(count($second))->toBe(0);
    expect(Insight::where('user_id', $user->id)->where('insight_key', WeightPlateauRule::KEY)->count())->toBe(1);
});

it('engine logs InsightRuleRun for every rule, fired or not', function () {
    $now = CarbonImmutable::parse('2026-05-04 09:00', 'Asia/Taipei');
    $user = User::factory()->create(['pandora_user_uuid' => 'u-runlog']);

    app(InsightEngine::class)->evaluateAllForUser($user, $now);

    expect(InsightRuleRun::where('user_id', $user->id)->whereDate('eval_date', '2026-05-04')->count())
        ->toBe(12); // 12 rules in PR #2 registry
});

it('aggregator returns null fields for users with no data', function () {
    $user = User::factory()->create(['pandora_user_uuid' => 'u-empty']);
    $snapshot = app(UserDataAggregator::class)->snapshotFor($user);

    expect($snapshot->weightAvg7d())->toBeNull();
    expect($snapshot->sleepAvgMinutes7d())->toBeNull();
    expect($snapshot->mealsKcalAvg7d())->toBeNull();
    expect($snapshot->fastingStreakDays())->toBe(0);
});
