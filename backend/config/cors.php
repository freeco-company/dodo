<?php

/*
 * CORS for the dodo frontend dev/web bundle.
 *
 * The frontend runs on http://localhost:5173 (http-server / python http.server)
 * while Laravel runs on http://localhost:8765. We need to allow Authorization
 * headers (Sanctum bearer) and JSON content from those origins.
 *
 * For production we will tighten allowed_origins to the deployed web/Capacitor
 * origin(s) once known. Capacitor itself uses the file:// or capacitor://
 * scheme and so does NOT trigger browser CORS — these settings only matter
 * for the web preview.
 */

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost:5174',
        'http://localhost:8080',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
