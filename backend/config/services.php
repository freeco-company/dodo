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

    /*
    |--------------------------------------------------------------------------
    | Pandora Core Conversion Service (ADR-003)
    |--------------------------------------------------------------------------
    | py-service 加盟轉換漏斗。沒設 base_url / shared_secret 時 publisher 進入
    | noop 模式（dev / Phase A 環境 fallback）。
    */
    /*
    |--------------------------------------------------------------------------
    | Dodo AI Service (ADR-002 §3 — Laravel ↔ Python)
    |--------------------------------------------------------------------------
    | ai-service/ FastAPI 端的位置 + 共用 secret。沒設 base_url / shared_secret
    | 時 AiServiceClient 維持 503（AI_SERVICE_DOWN）行為，與 Phase A 一致。
    | shared_secret 對應 ai-service 端的 INTERNAL_SHARED_SECRET（X-Internal-Secret
    | header）；Phase F 切真 JWT 後此 path 移除。
    */
    'meal_ai_service' => [
        'base_url' => env('MEAL_AI_SERVICE_BASE_URL'),
        'shared_secret' => env('MEAL_AI_SERVICE_SHARED_SECRET'),
        'timeout' => (int) env('MEAL_AI_SERVICE_TIMEOUT', 30),
    ],

    'pandora_conversion' => [
        'base_url' => env('PANDORA_CONVERSION_BASE_URL'),
        'shared_secret' => env('PANDORA_CONVERSION_SHARED_SECRET'),
        'app_id' => env('PANDORA_CONVERSION_APP_ID', 'doudou'),
        'timeout' => (int) env('PANDORA_CONVERSION_TIMEOUT', 5),
        // 婕樂纖（母艦）諮詢加盟頁面 URL — 朵朵 CTA 點擊後導去這裡（ADR-003 §2.3）。
        // 沒設環境變數時用 placeholder（dev / Phase A），production 必須在 env 覆寫。
        'franchise_url' => env('PANDORA_FRANCHISE_URL', 'https://js-store.com.tw/franchise/consult'),
        // 僅供 `php artisan demo:franchise-funnel --force-loyalist` 用：
        // 一個帶 `lifecycle:write` scope 的 service JWT，hit py-service
        // POST /api/v1/users/{uuid}/lifecycle/transition 強制升等。
        // production 不會用到；dev 可從 py-service 簽一張塞進 .env。
        'demo_admin_jwt' => env('PANDORA_DEMO_ADMIN_JWT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pandora Core gamification service (ADR-009)
    |--------------------------------------------------------------------------
    | Publishes XP / level / achievement events to py-service. Same py-service
    | host as conversion above; the gamification module shares the same HMAC
    | trust boundary but is wired with its own env vars to allow split
    | deployments later (e.g. a separate gamification micro-service).
    */
    'pandora_gamification' => [
        'base_url' => env('PANDORA_GAMIFICATION_BASE_URL', env('PANDORA_CONVERSION_BASE_URL')),
        'shared_secret' => env('PANDORA_GAMIFICATION_SHARED_SECRET', env('PANDORA_CONVERSION_SHARED_SECRET')),
        'timeout' => (int) env('PANDORA_GAMIFICATION_TIMEOUT', 5),
        // ADR-009 Phase B.2 — receive-side webhook from py-service.
        // Independent secret (different direction); set on prod when py-service
        // is configured with GAMIFICATION_CONSUMER_MEAL_SECRET pointing here.
        'webhook_secret' => env('PANDORA_GAMIFICATION_WEBHOOK_SECRET'),
        'webhook_window_seconds' => (int) env('PANDORA_GAMIFICATION_WEBHOOK_WINDOW_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase E — IAP (Apple / Google) verification
    |--------------------------------------------------------------------------
    | stub_mode=true  → verifiers accept STUB_APPLE_* and STUB_GOOGLE_*
    |                   tokens without contacting the provider; webhooks accept
    |                   `signature: STUB_VALID`. For dev / E2E only.
    | stub_mode=false → real path; missing keys throw IapNotConfiguredException
    |                   so the route returns 503 instead of pretending.
    */
    'iap' => [
        'stub_mode' => filter_var(env('IAP_STUB_MODE', false), FILTER_VALIDATE_BOOLEAN),
        'apple' => [
            'shared_secret' => env('IAP_APPLE_SHARED_SECRET'),
            'bundle_id' => env('IAP_APPLE_BUNDLE_ID', 'com.dodo.app'),
        ],
        'google' => [
            'service_account_json' => env('IAP_GOOGLE_SERVICE_ACCOUNT_JSON'),
            'package_name' => env('IAP_GOOGLE_PACKAGE_NAME', 'com.dodo.app'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase E — ECPay (綠界) recurring subscription
    |--------------------------------------------------------------------------
    | Test credentials from ECPay docs:
    |   merchant_id=2000132 / hash_key=pwFHCqoQZGmho4w6 / hash_iv=EkRm7iFT261dpevs
    | Production keys come from ECPay merchant console. Missing keys fail
    | closed (sign() throws); webhook handler returns "0|invalid_signature".
    */
    'ecpay' => [
        'merchant_id' => env('ECPAY_MERCHANT_ID'),
        'hash_key' => env('ECPAY_HASH_KEY'),
        'hash_iv' => env('ECPAY_HASH_IV'),
        'aio_endpoint' => env('ECPAY_AIO_ENDPOINT', 'https://payment-stage.ecpay.com.tw/Cashier/AioCheckOut/V5'),
        'return_url' => env('ECPAY_RETURN_URL'),
        'period_return_url' => env('ECPAY_PERIOD_RETURN_URL'),
        'client_back_url' => env('ECPAY_CLIENT_BACK_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase E — Analytics (PostHog)
    |--------------------------------------------------------------------------
    | api_key unset → AnalyticsService::flush is a no-op (DB writes still
    | happen unconditionally). Keeps CI green without leaking events to a
    | live PostHog project.
    */
    'posthog' => [
        'api_key' => env('POSTHOG_API_KEY'),
        'host' => env('POSTHOG_HOST', 'https://app.posthog.com'),
        'batch_size' => (int) env('POSTHOG_BATCH_SIZE', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase E — Push (FCM HTTP v1)
    |--------------------------------------------------------------------------
    | service_account_json unset → PushService::send logs + skips. The token
    | registration path is already independent of this and will keep working.
    | dry_run=true forces the FCM `validate_only` flag — used by tests to
    | exercise auth/serialisation without delivering a notification.
    */
    'fcm' => [
        'service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
        'project_id' => env('FCM_PROJECT_ID'),
        'dry_run' => filter_var(env('FCM_DRY_RUN', false), FILTER_VALIDATE_BOOLEAN),
    ],

];
