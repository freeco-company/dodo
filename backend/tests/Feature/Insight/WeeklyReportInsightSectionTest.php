<?php

use App\Models\Insight;
use App\Models\User;
use App\Services\WeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * SPEC-cross-metric-insight-v1 PR #5 — weekly report integration.
 * The 'insights' section in the weekly payload differs by tier:
 *   - free: count + preview_headline + paywall:true (no body)
 *   - paid: top_three + all + body fully visible
 */
function seedInsightForWeek(User $user, CarbonImmutable $firedAt, string $key = 'weight_plateau_detected'): Insight
{
    return Insight::create([
        'user_id' => $user->id,
        'insight_key' => $key,
        'idempotency_key' => 'u:'.$user->id.':'.$key.':'.uniqid(),
        'detection_payload' => ['x' => 1],
        'narrative_headline' => '妳的體重 5 天平台了 🌱',
        'narrative_body' => '不是停滯，是身體在適應',
        'action_suggestion' => [],
        'source' => 'rule_engine',
        'fired_at' => $firedAt,
    ]);
}

it('weekly report exposes insight count + preview but no body for free tier', function () {
    $weekStart = CarbonImmutable::parse('2026-04-26', 'Asia/Taipei')->startOfWeek(0); // Sunday
    $user = User::factory()->create(['pandora_user_uuid' => 'u-wr-free']);
    seedInsightForWeek($user, $weekStart->addDays(2));
    seedInsightForWeek($user, $weekStart->addDays(3), 'sleep_deficit_with_weight_stall');

    $payload = app(WeeklyReportService::class)->generate($user, $weekStart);

    expect($payload['insights']['count'])->toBe(2);
    expect($payload['insights']['paywall'])->toBeTrue();
    expect($payload['insights']['preview_headline'])->not->toBeEmpty();
    expect($payload['insights'])->not->toHaveKey('all');
    expect($payload['features']['insights_visible'])->toBeFalse();
});

it('weekly report exposes top_three + all for paid tier', function () {
    $weekStart = CarbonImmutable::parse('2026-04-26', 'Asia/Taipei')->startOfWeek(0);
    $user = User::factory()->create(['pandora_user_uuid' => 'u-wr-paid']);
    $user->update(['subscription_type' => 'monthly', 'subscription_expires_at_iso' => now()->addMonth()]);

    foreach (range(0, 4) as $i) {
        seedInsightForWeek($user, $weekStart->addDays($i), 'weight_plateau_detected_'.$i);
    }

    $payload = app(WeeklyReportService::class)->generate($user, $weekStart);

    expect($payload['insights']['count'])->toBe(5);
    expect($payload['insights']['paywall'])->toBeFalse();
    expect($payload['insights']['top_three'])->toHaveCount(3);
    expect($payload['insights']['all'])->toHaveCount(5);
    expect($payload['features']['insights_visible'])->toBeTrue();
});

it('weekly report insights section is empty when no insights fired in the week', function () {
    $weekStart = CarbonImmutable::parse('2026-04-26', 'Asia/Taipei')->startOfWeek(0);
    $user = User::factory()->create(['pandora_user_uuid' => 'u-wr-empty']);

    $payload = app(WeeklyReportService::class)->generate($user, $weekStart);

    expect($payload['insights']['count'])->toBe(0);
    expect($payload['insights']['top_three'])->toBe([]);
});
