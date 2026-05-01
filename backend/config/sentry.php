<?php

/*
 * Sentry Laravel SDK config — pre-launch crash reporting (pandora-meal).
 *
 * - DSN 留空時 SDK 自動 noop（local / CI 不噴到 prod project）。
 * - PII scrub 在 before_send hook 做（見 App\Support\Sentry\BeforeSendScrubber）。
 * - enabled 限制只有 production / staging 才送，local / testing 不送。
 */

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // Sentry release（可選；deploy script 帶 git sha）
    'release' => env('SENTRY_RELEASE'),

    'environment' => env('APP_ENV', 'production'),

    // SDK gating — 沒設 SENTRY_LARAVEL_DSN（dev / test）→ Sentry SDK 自動 noop。
    // 額外保險：bootstrap/app.php reportable() 也會檢查 DSN 是否設定才 forward。
    // (註：Sentry config 沒有 'enabled' 這個 option — 移除避免 OptionsResolver 抱怨)

    // 為了符合 Apple privacy nutrition + 集團 PII policy（ADR-007 §2.3），
    // 預設不抓 user / 不抓 cookies / 不抓 headers。BeforeSend 還會再 scrub 一次。
    'send_default_pii' => false,

    // Performance / tracing — 預設關閉，省 quota；要追慢 query 再打開。
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE', 0.0),
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),

    // breadcrumbs：sql / query log 容易夾帶 email / token，全關。
    'breadcrumbs' => [
        'logs' => true,
        'cache' => false,
        'livewire' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => false,
        'notifications' => false,
    ],

    // 自家 PII scrubber — 砍 email / phone / password / *token* / apple_id / line_id
    'before_send' => [\App\Support\Sentry\BeforeSendScrubber::class, 'scrub'],

    // 不要把 vendor / Laravel internal stack frame 標成 in_app（noise）
    'in_app_exclude' => [
        '/vendor',
    ],

    'integrations' => [
        // 預設整合即可
    ],
];
