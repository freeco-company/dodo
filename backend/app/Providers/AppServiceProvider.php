<?php

namespace App\Providers;

use App\Models\User;
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
    }
}
