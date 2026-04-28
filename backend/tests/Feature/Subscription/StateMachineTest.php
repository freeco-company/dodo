<?php

use App\Models\User;
use App\Services\Subscription\SubscriptionStateMachine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('transitions trial → active → grace → expired', function () {
    $user = User::factory()->create();
    /** @var SubscriptionStateMachine $sm */
    $sm = app(SubscriptionStateMachine::class);

    $sub = $sm->findOrInitialise($user, 'apple', 'sm-otid-1', 'dodo.subscription.monthly', 'app_monthly');
    $sub->save();
    expect($sub->state)->toBe('trial');

    $sm->activate($sub, now()->subDay(), now()->addDays(30));
    expect($sub->fresh()->state)->toBe('active');

    $sm->moveToGrace($sub, now()->addDays(16));
    expect($sub->fresh()->state)->toBe('grace');

    $sm->expire($sub);
    expect($sub->fresh()->state)->toBe('expired');
});

it('rejects illegal transition expired → active', function () {
    $user = User::factory()->create();
    /** @var SubscriptionStateMachine $sm */
    $sm = app(SubscriptionStateMachine::class);

    $sub = $sm->findOrInitialise($user, 'apple', 'sm-otid-2', null, 'app_monthly');
    $sub->save();
    $sm->activate($sub, now(), now()->addDays(30));
    $sm->expire($sub);

    expect(fn () => $sm->activate($sub, now(), now()->addDays(30)))
        ->toThrow(InvalidArgumentException::class);
});

it('observer mirrors active subscription to legacy User columns', function () {
    $user = User::factory()->create([
        'subscription_type' => 'none',
        'subscription_expires_at_iso' => null,
    ]);
    /** @var SubscriptionStateMachine $sm */
    $sm = app(SubscriptionStateMachine::class);
    $sub = $sm->findOrInitialise($user, 'ecpay', 'sm-trade-1', null, 'app_yearly');
    $sub->save();
    $end = now()->addYear();
    $sm->activate($sub, now(), $end);

    $user->refresh();
    expect($user->subscription_type)->toBe('app_yearly');
    expect(Carbon::parse((string) $user->subscription_expires_at_iso)->toIso8601String())
        ->toBe($end->toIso8601String());
});

it('observer drops mirror when only refunded sub remains', function () {
    $user = User::factory()->create();
    /** @var SubscriptionStateMachine $sm */
    $sm = app(SubscriptionStateMachine::class);
    $sub = $sm->findOrInitialise($user, 'apple', 'sm-otid-3', null, 'app_monthly');
    $sub->save();
    $sm->activate($sub, now(), now()->addDays(30));
    expect($user->fresh()->subscription_type)->toBe('app_monthly');

    $sm->refund($sub);
    expect($user->fresh()->subscription_type)->toBe('none');
});
