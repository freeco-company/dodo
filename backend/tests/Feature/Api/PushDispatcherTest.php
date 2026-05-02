<?php

use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\PushDispatcher;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * SPEC-02 / 04 / 06 push template smoke tests.
 *
 * FCM is not configured in tests → PushService returns
 * { sent: 0, skipped: 0 or count, reason: 'fcm_not_configured' or 'no_tokens' }.
 * That's fine — we're testing the dispatcher logic, not FCM itself.
 */

beforeEach(function () {
    Http::fake();
});

it('weeklyReportReady returns no_tokens reason when user has no push tokens', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 3, 4, 0, 0, 'UTC')); // 12:00 Asia/Taipei (not quiet)
    $user = User::factory()->create();
    $dispatcher = app(PushDispatcher::class);

    $result = $dispatcher->weeklyReportReady($user, '2026-05-09');

    expect($result['sent'])->toBe(0);
    expect($result['reason'])->toBe('no_tokens');
    Carbon::setTestNow();
});

it('weeklyReportReady skips during quiet hours', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 3, 14, 0, 0, 'UTC')); // 22:00 Asia/Taipei
    $user = User::factory()->create();
    $dispatcher = app(PushDispatcher::class);

    $result = $dispatcher->weeklyReportReady($user, '2026-05-09');

    expect($result['reason'])->toBe('quiet_hours');
    Carbon::setTestNow();
});

it('fastingPreEat is paid-only', function () {
    $user = User::factory()->create();
    $dispatcher = app(PushDispatcher::class);
    Carbon::setTestNow(Carbon::create(2026, 5, 3, 5, 0, 0, 'UTC')); // 13:00 Asia/Taipei (not quiet)

    $result = $dispatcher->fastingPreEat($user, '16:8');
    expect($result['reason'])->toBe('paid_only_template');

    Carbon::setTestNow();
});

it('fastingPreEat works for fp_lifetime users (then no_tokens)', function () {
    $user = User::factory()->create(['membership_tier' => 'fp_lifetime']);
    $dispatcher = app(PushDispatcher::class);
    Carbon::setTestNow(Carbon::create(2026, 5, 3, 5, 0, 0, 'UTC'));

    $result = $dispatcher->fastingPreEat($user, '16:8');
    expect($result['reason'])->toBe('no_tokens');

    Carbon::setTestNow();
});

it('fastingStreakAtRisk bypasses quiet hours (allowed inside 22-23:59)', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 3, 14, 30, 0, 'UTC')); // 22:30 Asia/Taipei
    $user = User::factory()->create();
    $dispatcher = app(PushDispatcher::class);

    $result = $dispatcher->fastingStreakAtRisk($user, 5);

    // No quiet_hours skip — falls through to no_tokens
    expect($result['reason'])->toBe('no_tokens');
    Carbon::setTestNow();
});

it('seasonalRelease skips quiet hours', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 3, 23, 0, 0, 'UTC')); // 07:00 Asia/Taipei (still quiet)
    $user = User::factory()->create();
    $dispatcher = app(PushDispatcher::class);

    $result = $dispatcher->seasonalRelease($user, '春櫻系列');

    expect($result['reason'])->toBe('quiet_hours');
    Carbon::setTestNow();
});

it('seasonalRelease passes quiet-hours guard at noon', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 3, 4, 0, 0, 'UTC')); // 12:00 Asia/Taipei
    $user = User::factory()->create();
    $dispatcher = app(PushDispatcher::class);

    $result = $dispatcher->seasonalRelease($user, '春櫻系列');

    expect($result['reason'])->toBe('no_tokens');
    Carbon::setTestNow();
});

it('reports:notify-weekly artisan command runs without error on empty system', function () {
    $this->artisan('reports:notify-weekly')->assertSuccessful();
});

it('seasonal:notify artisan command runs without error on empty system', function () {
    $this->artisan('seasonal:notify')->assertSuccessful();
});

it('reports:notify-weekly fans out to users with reports', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 3, 12, 0, 0, 'UTC')); // 20:00 Asia/Taipei
    $user = User::factory()->create();
    $weekStart = Carbon::today()->startOfWeek(Carbon::SUNDAY)->toDateString();
    WeeklyReport::create([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'week_start' => $weekStart,
        'week_end' => Carbon::today()->startOfWeek(Carbon::SUNDAY)->addDays(6)->toDateString(),
    ]);

    $this->artisan('reports:notify-weekly')->assertSuccessful();
    Carbon::setTestNow();
});
