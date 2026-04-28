<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pandora Core Identity (ADR-007 Phase 4)
    |--------------------------------------------------------------------------
    | platform 是身份的 single source of truth。朵朵的 IdentityClient SDK 用
    | base_url 拉 public key 驗 RS256 JWT，用 webhook_secret 驗 platform 推來的
    | user.upserted webhook。
    */
    'pandora_core' => [
        'base_url' => env('PANDORA_CORE_BASE_URL'),
        'jwt_issuer' => env('PANDORA_CORE_JWT_ISSUER', 'pandora-core-identity'),
        'jwt_audience' => env('PANDORA_CORE_JWT_AUDIENCE', 'dodo'),
        'webhook_secret' => env('PANDORA_CORE_WEBHOOK_SECRET'),
        'webhook_window_seconds' => (int) env('PANDORA_CORE_WEBHOOK_WINDOW_SECONDS', 300),
    ],

];
