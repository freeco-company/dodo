<?php

use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function makeSub(User $user, string $state, array $extra = []): Subscription
{
    return Subscription::create(array_merge([
        'user_id' => $user->id,
        'pandora_user_uuid' => $user->pandora_user_uuid,
        'provider' => 'apple',
        'provider_subscription_id' => 'apple-'.uniqid(),
        'product_id' => 'app_monthly',
        'plan' => 'app_monthly',
        'state' => $state,
        'current_period_start' => Carbon::now()->subDays(30),
        'current_period_end' => Carbon::now()->addDay(),
    ], $extra));
}

it('expires active rows whose period_end is past --grace-hours', function () {
    $u = User::factory()->create();
    $sub = makeSub($u, 'active', [
        'current_period_end' => Carbon::now()->subHours(7),
    ]);

    Artisan::call('subscription:lifecycle-sweep', ['--grace-hours' => 6]);

    expect($sub->fresh()->state)->toBe('expired');
});

it('does NOT expire active rows still within grace window', function () {
    $u = User::factory()->create();
    $sub = makeSub($u, 'active', [
        'current_period_end' => Carbon::now()->subHours(2),
    ]);

    Artisan::call('subscription:lifecycle-sweep', ['--grace-hours' => 6]);

    expect($sub->fresh()->state)->toBe('active');
});

it('expires grace rows whose grace_until is past', function () {
    $u = User::factory()->create();
    $sub = makeSub($u, 'grace', [
        'current_period_end' => Carbon::now()->subDays(3),
        'grace_until' => Carbon::now()->subHour(),
    ]);

    Artisan::call('subscription:lifecycle-sweep');

    expect($sub->fresh()->state)->toBe('expired');
});

it('does NOT expire grace rows still within grace_until', function () {
    $u = User::factory()->create();
    $sub = makeSub($u, 'grace', [
        'current_period_end' => Carbon::now()->subDays(3),
        'grace_until' => Carbon::now()->addHour(),
    ]);

    Artisan::call('subscription:lifecycle-sweep');

    expect($sub->fresh()->state)->toBe('grace');
});

it('--dry-run lists candidates without writing', function () {
    $u = User::factory()->create();
    $sub = makeSub($u, 'active', [
        'current_period_end' => Carbon::now()->subHours(10),
    ]);

    Artisan::call('subscription:lifecycle-sweep', ['--grace-hours' => 6, '--dry-run' => true]);

    expect($sub->fresh()->state)->toBe('active');
});

it('does not touch trial / expired / refunded rows', function () {
    $u = User::factory()->create();
    $trial = makeSub($u, 'trial', ['current_period_end' => Carbon::now()->subDay()]);
    $expired = makeSub($u, 'expired', ['current_period_end' => Carbon::now()->subWeek()]);
    $refunded = makeSub($u, 'refunded', ['current_period_end' => Carbon::now()->subWeek()]);

    Artisan::call('subscription:lifecycle-sweep');

    expect($trial->fresh()->state)->toBe('trial')
        ->and($expired->fresh()->state)->toBe('expired')
        ->and($refunded->fresh()->state)->toBe('refunded');
});

it('mirrors expiry to User legacy columns via observer', function () {
    $u = User::factory()->create([
        'subscription_type' => 'app_monthly',
        'subscription_expires_at_iso' => Carbon::now()->subHours(10),
    ]);
    makeSub($u, 'active', [
        'current_period_end' => Carbon::now()->subHours(10),
    ]);

    Artisan::call('subscription:lifecycle-sweep', ['--grace-hours' => 6]);

    $u->refresh();
    expect($u->subscription_type)->toBe('none');
});

it('sweep is idempotent — second run is a no-op', function () {
    $u = User::factory()->create();
    $sub = makeSub($u, 'active', [
        'current_period_end' => Carbon::now()->subHours(10),
    ]);

    Artisan::call('subscription:lifecycle-sweep', ['--grace-hours' => 6]);
    expect($sub->fresh()->state)->toBe('expired');

    // Second run finds nothing to do
    Artisan::call('subscription:lifecycle-sweep', ['--grace-hours' => 6]);
    expect($sub->fresh()->state)->toBe('expired');
});
