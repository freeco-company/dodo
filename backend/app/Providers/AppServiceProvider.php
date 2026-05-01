<?php

namespace App\Providers;

use App\Events\ConversionEventPublished;
use App\Events\UserOptedOutFranchiseCta;
use App\Listeners\RecordFranchiseLead;
use App\Listeners\SilenceFranchiseLeads;
use App\Models\Subscription;
use App\Models\User;
use App\Observers\SubscriptionObserver;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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

        // UX sensitivity follow-up — write franchise leads inbox & honor opt-out.
        // 不放 EventServiceProvider 是為了讓 wiring 集中在這個 boot()，避免
        // 朵朵 repo 為了兩條 listener 多生一個 provider 檔。
        Event::listen(ConversionEventPublished::class, RecordFranchiseLead::class);
        Event::listen(UserOptedOutFranchiseCta::class, SilenceFranchiseLeads::class);

        // Pre-launch security hardening — credential stuffing throttle.
        // `login` limiter keys on email + IP so a single bad actor across
        // many emails AND a botnet against one email both get throttled.
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email', '');

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
