<?php

namespace App\Providers;

use App\Models\Subscription;
use App\Models\User;
use App\Observers\SubscriptionObserver;
use App\Observers\UserObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Phase D Wave 1 — legacy User saved → 自動 ensureMirror DodoUser
        // @see ADR-007 §2.3
        User::observe(UserObserver::class);

        // Phase E — Subscription state machine writes mirror to legacy User columns.
        Subscription::observe(SubscriptionObserver::class);
    }
}
