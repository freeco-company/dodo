<?php

use App\Models\UsageLog;
use App\Models\User;
use App\Services\Ai\AiCostCapExceeded;
use App\Services\Ai\AiCostGuardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.ai_cost_guard.enabled', true);
    config()->set('services.ai_cost_guard.monthly_cap_usd', 2.50);
    config()->set('services.ai_cost_guard.pricing', [
        'claude-haiku-4-5' => ['input' => 1.0, 'output' => 5.0],
        'claude-sonnet-4-6' => ['input' => 3.0, 'output' => 15.0],
        'default' => ['input' => 5.0, 'output' => 25.0],
    ]);
});

it('records token usage as a UsageLog row', function () {
    $u = User::factory()->create();
    $svc = app(AiCostGuardService::class);

    $row = $svc->record($u, 'chat', 'claude-haiku-4-5', 1000, 500, 1234);

    expect($row->kind)->toBe('chat')
        ->and($row->model)->toBe('claude-haiku-4-5')
        ->and($row->input_tokens)->toBe(1000)
        ->and($row->output_tokens)->toBe(500)
        ->and($row->tokens)->toBe(1500)
        ->and($row->latency_ms)->toBe(1234);
});

it('computes monthly cost from input × output rates', function () {
    $u = User::factory()->create();
    $svc = app(AiCostGuardService::class);

    // 1M input + 1M output on haiku = 1.0 + 5.0 = 6.0 USD
    $svc->record($u, 'chat', 'claude-haiku-4-5', 1_000_000, 1_000_000);

    expect($svc->monthlyCostUsd($u))->toBe(6.0);
});

it('falls back to default pricing for unknown model', function () {
    $u = User::factory()->create();
    $svc = app(AiCostGuardService::class);

    // 1M input + 1M output @ default (5/25) = 30 USD
    $svc->record($u, 'chat', 'gpt-9000', 1_000_000, 1_000_000);

    expect($svc->monthlyCostUsd($u))->toBe(30.0);
});

it('blends legacy rows with no input/output split using 50/50 total', function () {
    $u = User::factory()->create();
    UsageLog::create([
        'user_id' => $u->id,
        'pandora_user_uuid' => $u->pandora_user_uuid,
        'date' => Carbon::now('Asia/Taipei')->toDateString(),
        'kind' => 'chat',
        'model' => 'claude-haiku-4-5',
        'tokens' => 2_000_000,
        'input_tokens' => null,
        'output_tokens' => null,
    ]);

    // 1M input + 1M output = 1.0 + 5.0 = 6.0 USD (haiku)
    expect(app(AiCostGuardService::class)->monthlyCostUsd($u))->toBe(6.0);
});

it('only sums current calendar month rows', function () {
    $u = User::factory()->create();
    $svc = app(AiCostGuardService::class);

    Carbon::setTestNow(Carbon::parse('2026-04-29 10:00:00', 'Asia/Taipei'));

    UsageLog::create([
        'user_id' => $u->id,
        'pandora_user_uuid' => $u->pandora_user_uuid,
        'date' => '2026-03-15',  // last month
        'kind' => 'chat',
        'model' => 'claude-haiku-4-5',
        'tokens' => 2_000_000,
        'input_tokens' => 1_000_000,
        'output_tokens' => 1_000_000,
    ]);
    $svc->record($u, 'chat', 'claude-haiku-4-5', 500_000, 500_000);

    // last month row excluded; only current = 0.5M*1.0 + 0.5M*5.0 = 3.0
    expect($svc->monthlyCostUsd($u))->toBe(3.0);

    Carbon::setTestNow();
});

it('assertWithinBudget throws when over cap', function () {
    $u = User::factory()->create();
    $svc = app(AiCostGuardService::class);

    // 1M input + 0.5M output haiku = 1.0 + 2.5 = 3.5 > 2.50 cap
    $svc->record($u, 'chat', 'claude-haiku-4-5', 1_000_000, 500_000);

    expect(fn () => $svc->assertWithinBudget($u))
        ->toThrow(AiCostCapExceeded::class);
});

it('assertWithinBudget passes when under cap', function () {
    $u = User::factory()->create();
    $svc = app(AiCostGuardService::class);

    $svc->record($u, 'chat', 'claude-haiku-4-5', 100_000, 100_000); // 0.6 USD

    $svc->assertWithinBudget($u);
    expect(true)->toBeTrue();
});

it('cost guard disabled → never throws even when over cap', function () {
    config()->set('services.ai_cost_guard.enabled', false);
    $u = User::factory()->create();
    $svc = app(AiCostGuardService::class);

    $svc->record($u, 'chat', 'claude-haiku-4-5', 5_000_000, 5_000_000); // 30 USD

    $svc->assertWithinBudget($u); // would throw if enabled
    expect($svc->isOverCap($u))->toBeFalse();
});

it('snapshot returns spent / cap / fraction / over flag', function () {
    $u = User::factory()->create();
    $svc = app(AiCostGuardService::class);

    $svc->record($u, 'chat', 'claude-haiku-4-5', 250_000, 250_000); // 1.5 USD

    $snap = $svc->snapshot($u);
    expect($snap['spent_usd'])->toBe(1.5)
        ->and($snap['cap_usd'])->toBe(2.5)
        ->and($snap['fraction'])->toBe(0.6)
        ->and($snap['over'])->toBeFalse();
});
