<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily at 03:30 UTC = 11:30 Asia/Taipei — off-peak.
// Catches Subscription rows whose Apple/Google lifecycle webhook never landed.
Schedule::command('subscription:lifecycle-sweep')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->onOneServer();

// ADR-007 §6 #4 mitigation (b) — hourly identity reconcile delta pull
// from Pandora Core. Catches webhooks that the publisher dropped /
// dead-lettered. Cheap (small JSON, indexed query); 1h matches the TTL
// recommendation for consumer mirrors.
Schedule::command('identity:reconcile')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
