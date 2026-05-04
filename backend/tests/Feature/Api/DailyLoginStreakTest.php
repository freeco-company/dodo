<?php

use App\Models\User;
use App\Models\UserDailyStreak;
use App\Services\Dodo\Streak\DailyLoginStreakService;
use App\Services\Gamification\GamificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 4, 9, 0, 0, 'Asia/Taipei'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('first login creates streak=1 and is_first_today=true', function () {
    $user = User::factory()->create();
    $svc = app(DailyLoginStreakService::class);

    $r = $svc->recordLogin($user);

    expect($r['streak'])->toBe(1)
        ->and($r['is_first_today'])->toBeTrue()
        ->and($r['is_milestone'])->toBeTrue() // 1 is in milestones
        ->and($r['milestone_label'])->toBe('第一天')
        ->and($r['today_date'])->toBe('2026-05-04');

    $row = UserDailyStreak::where('user_id', $user->id)->firstOrFail();
    expect((int) $row->current_streak)->toBe(1)
        ->and((int) $row->longest_streak)->toBe(1)
        ->and($row->last_login_date->toDateString())->toBe('2026-05-04');
});

it('second login same day is no-op (is_first_today=false)', function () {
    $user = User::factory()->create();
    $svc = app(DailyLoginStreakService::class);

    $svc->recordLogin($user);
    Carbon::setTestNow(Carbon::create(2026, 5, 4, 18, 0, 0, 'Asia/Taipei'));
    $r = $svc->recordLogin($user);

    expect($r['streak'])->toBe(1)
        ->and($r['is_first_today'])->toBeFalse()
        ->and($r['is_milestone'])->toBeFalse(); // milestone only when is_first_today=true
});

it('next-day login increments streak to 2', function () {
    $user = User::factory()->create();
    $svc = app(DailyLoginStreakService::class);

    $svc->recordLogin($user);
    Carbon::setTestNow(Carbon::create(2026, 5, 5, 9, 0, 0, 'Asia/Taipei'));
    $r = $svc->recordLogin($user);

    expect($r['streak'])->toBe(2)
        ->and($r['is_first_today'])->toBeTrue()
        ->and($r['is_milestone'])->toBeFalse(); // 2 not in milestones
});

it('skipping a day resets streak to 1', function () {
    $user = User::factory()->create();
    $svc = app(DailyLoginStreakService::class);

    $svc->recordLogin($user); // 2026-05-04
    Carbon::setTestNow(Carbon::create(2026, 5, 5, 9, 0, 0, 'Asia/Taipei'));
    $svc->recordLogin($user); // 2026-05-05 streak=2
    Carbon::setTestNow(Carbon::create(2026, 5, 7, 9, 0, 0, 'Asia/Taipei'));
    $r = $svc->recordLogin($user); // skipped 5/6 → reset

    expect($r['streak'])->toBe(1)
        ->and($r['is_first_today'])->toBeTrue();

    $row = UserDailyStreak::where('user_id', $user->id)->firstOrFail();
    // longest preserved
    expect((int) $row->longest_streak)->toBe(2);
});

it('triggers milestone at 1, 3, 7, 14, 21, 30, 60, 100', function () {
    $user = User::factory()->create();
    $svc = app(DailyLoginStreakService::class);

    $milestones = [1, 3, 7, 14, 21, 30, 60, 100];
    $hitMilestones = [];

    $start = Carbon::create(2026, 1, 1, 9, 0, 0, 'Asia/Taipei');
    for ($day = 0; $day < 105; $day++) {
        Carbon::setTestNow($start->copy()->addDays($day));
        $r = $svc->recordLogin($user);
        if ($r['is_milestone']) {
            $hitMilestones[] = $r['streak'];
        }
    }

    expect($hitMilestones)->toBe($milestones);
});

it('publish failures do not break the recordLogin flow', function () {
    // Bind a publisher stub that throws on publish().
    $stub = new class extends GamificationPublisher
    {
        public bool $called = false;

        public function publish(
            string $pandoraUserUuid,
            string $eventKind,
            string $idempotencyKey,
            array $metadata = [],
            ?\Carbon\CarbonInterface $occurredAt = null,
        ): void {
            $this->called = true;
            throw new \RuntimeException('simulated publish failure');
        }
    };
    app()->instance(GamificationPublisher::class, $stub);

    $user = User::factory()->create(['pandora_user_uuid' => 'test-uuid-xyz']);
    $svc = app(DailyLoginStreakService::class);

    // streak=3 hits the publish path (PUBLISH_KIND_BY_STREAK)
    $start = Carbon::create(2026, 1, 1, 9, 0, 0, 'Asia/Taipei');
    Carbon::setTestNow($start);
    $svc->recordLogin($user);
    Carbon::setTestNow($start->copy()->addDay());
    $svc->recordLogin($user);
    Carbon::setTestNow($start->copy()->addDays(2));
    $r = $svc->recordLogin($user);

    expect($r['streak'])->toBe(3)
        ->and($r['is_milestone'])->toBeTrue()
        ->and($stub->called)->toBeTrue();
});

it('GET /api/streak/today returns current streak json + sets X-Streak header', function () {
    $user = User::factory()->create();

    Sanctum::actingAs($user);
    $resp = $this->getJson('/api/streak/today');

    $resp->assertOk()
        ->assertJsonPath('current_streak', 1)
        ->assertJsonPath('is_first_today', true)
        ->assertJsonPath('today_date', '2026-05-04');

    expect($resp->headers->get('X-Streak'))->not->toBeNull();
});

it('GET /api/streak/today is no-op on second call same day', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $this->getJson('/api/streak/today')->assertOk();
    $this->getJson('/api/streak/today')
        ->assertOk()
        ->assertJsonPath('current_streak', 1)
        ->assertJsonPath('is_first_today', false);
});

it('GET /api/streak/today requires auth', function () {
    $this->getJson('/api/streak/today')->assertStatus(401);
});
