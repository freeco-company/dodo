<?php

/*
 * CORS for the dodo frontend (web preview + Capacitor iOS / Android).
 *
 * Pre-launch hardening (2026-05-01):
 *   - Tightened allowed_origins to explicit prod + dev list (was wildcard-ish dev only).
 *   - Pinned allowed_methods + allowed_headers (no more `*`) — the iOS Capacitor
 *     bridge issues file:// / capacitor:// requests that don't trigger CORS,
 *     so the pinning is for browser preview + future PWA paths only.
 *   - supports_credentials false (no cookie-based auth — all requests carry a
 *     Sanctum bearer in Authorization). Setting to true would force browser to
 *     omit `*` origin echoing and isn't needed.
 *
 * Capacitor schemes (`capacitor://localhost`, `https://localhost`) are added
 * defensively — newer Capacitor (≥ 3) on iOS uses `capacitor://localhost`
 * and on Android uses `https://localhost` as the page origin. Without them
 * any CORS preflight from the wrapper would fail.
 */

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [
        // Production
        'https://meal-api.js-store.com.tw',
        // Capacitor (iOS / Android) — see note above
        'capacitor://localhost',
        'https://localhost',
        // Local dev
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',
        'http://localhost:8080',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'Accept',
        // py-service / mothership webhook headers (used in admin dashboards
        // when ops manually replays via browser tooling).
        'X-Pandora-Event-Id',
        'X-Pandora-Timestamp',
        'X-Pandora-Signature',
    ],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
