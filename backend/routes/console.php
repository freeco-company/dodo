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

// 集團合規硬規則（docs/group-fp-product-compliance.md）— 每日 04:00 UTC 掃 KB
// articles 找新踩線詞 auto-rewrite。使用 freeco/pandora-shared 共用 sanitizer，
// 與母艦 compliance:audit 同套詞庫。
Schedule::command('compliance:audit', ['--apply'])
    ->dailyAt('04:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/compliance-audit.log'));

// Pre-launch security: prune expired Sanctum personal access tokens daily.
// Pairs with sanctum.expiration = 30d. Without this the personal_access_tokens
// table grows unbounded and revoked / aged tokens linger for grep on breach.
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('05:00')
    ->withoutOverlapping()
    ->onOneServer();

// SPEC-weekly-ai-report §3 — pre-generate current-week reports Sunday 19:00 (Asia/Taipei).
Schedule::command('reports:generate-weekly')
    ->weeklyOn(\Illuminate\Console\Scheduling\Schedule::SUNDAY, '19:00')
    ->timezone('Asia/Taipei')
    ->withoutOverlapping()
    ->onOneServer();

// SPEC-healthkit-integration §5 retention — wipe raw HK payloads older than 90d.
Schedule::command('health:prune --days=90')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->onOneServer();

// Pre-launch security: prune webhook nonce rows older than 30 days.
// Replay window is 5 minutes; we keep 30 days for ops debugging / audit.
// Cheap delete: each table has a unique index on event_id (or nonce) and a
// timestamp column; a single DELETE … WHERE received_at < cutoff is fine.
Schedule::call(function () {
    $cutoff = now()->subDays(30);
    foreach ([
        'identity_webhook_nonces',
        'gamification_webhook_nonces',
        'lifecycle_invalidate_nonces',
        'franchisee_webhook_nonces',
    ] as $table) {
        \Illuminate\Support\Facades\DB::table($table)
            ->where('received_at', '<', $cutoff)
            ->delete();
    }
})
    ->name('webhook-nonce-prune')
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->onOneServer();
