<?php

use App\Models\HealthMetric;
use App\Models\Meal;
use App\Models\MonthlyCollage;
use App\Models\ProgressSnapshot;
use App\Models\RitualEvent;
use App\Models\User;
use App\Services\HealthMetricsService;
use App\Services\Ritual\MonthlyCollageGenerator;
use App\Services\Ritual\PhotoSelector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedSnapshot(User $user, CarbonImmutable $takenAt, int $weightG = 60000): ProgressSnapshot
{
    return ProgressSnapshot::create([
        'user_id' => $user->id,
        'taken_at' => $takenAt,
        'weight_g' => $weightG,
    ]);
}

function paidUser(string $uuid): User
{
    $u = User::factory()->create([
        'pandora_user_uuid' => $uuid,
        'subscription_type' => 'monthly',
        'subscription_expires_at_iso' => now()->addMonth(),
    ]);

    return $u;
}

it('PhotoSelector returns empty collection when fewer than 4 snapshots', function () {
    $user = User::factory()->create();
    $month = CarbonImmutable::parse('2026-04-01', 'Asia/Taipei');
    seedSnapshot($user, $month->addDays(2));
    seedSnapshot($user, $month->addDays(8));
    seedSnapshot($user, $month->addDays(15));

    expect(app(PhotoSelector::class)->selectForMonth($user, $month))->toBeEmpty();
});

it('PhotoSelector keeps all when between 4 and 9 snapshots', function () {
    $user = User::factory()->create();
    $month = CarbonImmutable::parse('2026-04-01', 'Asia/Taipei');
    foreach ([2, 6, 12, 18, 24] as $d) {
        seedSnapshot($user, $month->addDays($d));
    }

    $picked = app(PhotoSelector::class)->selectForMonth($user, $month);

    expect($picked->count())->toBe(5);
});

it('PhotoSelector samples down to 9 when more than 9 snapshots', function () {
    $user = User::factory()->create();
    $month = CarbonImmutable::parse('2026-04-01', 'Asia/Taipei');
    foreach (range(0, 14) as $i) {
        seedSnapshot($user, $month->addDays($i * 2));
    }

    $picked = app(PhotoSelector::class)->selectForMonth($user, $month);

    expect($picked->count())->toBeLessThanOrEqual(9);
    expect($picked->count())->toBeGreaterThanOrEqual(4);
});

it('MonthlyCollageGenerator returns null when not enough snapshots', function () {
    $user = paidUser('u-no-snap');
    $month = CarbonImmutable::parse('2026-04-01', 'Asia/Taipei');

    $collage = app(MonthlyCollageGenerator::class)->generateForUser($user, $month);

    expect($collage)->toBeNull();
    expect(MonthlyCollage::count())->toBe(0);
});

it('MonthlyCollageGenerator creates collage + dispatches ritual event when eligible', function () {
    $user = paidUser('u-collage');
    $month = CarbonImmutable::parse('2026-04-01', 'Asia/Taipei');
    foreach ([2, 6, 12, 18, 24] as $d) {
        seedSnapshot($user, $month->addDays($d));
    }
    foreach ([3, 7, 11, 15] as $d) {
        Meal::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => $month->addDays($d)->toDateString(),
            'meal_type' => 'lunch', 'food_name' => 'x',
            'recognized_via' => 'manual',
            'calories' => 600, 'carbs_g' => 60, 'protein_g' => 30, 'fat_g' => 15,
        ]);
        HealthMetric::create([
            'user_id' => $user->id,
            'type' => HealthMetricsService::TYPE_STEPS,
            'value' => 8000, 'unit' => 'steps',
            'recorded_at' => $month->addDays($d), 'source' => 'test',
        ]);
    }

    $collage = app(MonthlyCollageGenerator::class)->generateForUser($user, $month);

    expect($collage)->not->toBeNull();
    expect($collage->snapshot_ids)->toHaveCount(5);
    expect($collage->stats_payload['food_days_logged'])->toBe(4);
    expect($collage->stats_payload['steps_total'])->toBe(32000);
    expect($collage->narrative_letter)->toContain('堅持');

    expect(RitualEvent::where('user_id', $user->id)->where('ritual_key', RitualEvent::KEY_MONTHLY_COLLAGE)->count())->toBe(1);
});

it('MonthlyCollageGenerator is idempotent — second run for same month does not duplicate', function () {
    $user = paidUser('u-idemp');
    $month = CarbonImmutable::parse('2026-04-01', 'Asia/Taipei');
    foreach ([2, 6, 12, 18, 24] as $d) {
        seedSnapshot($user, $month->addDays($d));
    }

    app(MonthlyCollageGenerator::class)->generateForUser($user, $month);
    app(MonthlyCollageGenerator::class)->generateForUser($user, $month);

    expect(MonthlyCollage::where('user_id', $user->id)->count())->toBe(1);
    expect(RitualEvent::where('user_id', $user->id)->where('ritual_key', RitualEvent::KEY_MONTHLY_COLLAGE)->count())->toBe(1);
});

it('artisan command runs without errors', function () {
    paidUser('u-cmd-1');
    $exit = $this->artisan('progress:generate-monthly-collage', ['--month' => '2026-04']);
    $exit->assertSuccessful();
});

it('narrative letter contains zero compliance violations', function () {
    $user = paidUser('u-comp');
    $month = CarbonImmutable::parse('2026-04-01', 'Asia/Taipei');
    foreach ([2, 6, 12, 18, 24] as $d) {
        seedSnapshot($user, $month->addDays($d));
    }
    $collage = app(MonthlyCollageGenerator::class)->generateForUser($user, $month);

    $sanitizer = new \Pandora\Shared\Compliance\LegalContentSanitizer;
    expect($sanitizer->riskReport($collage->narrative_letter))->toBe([]);
});
