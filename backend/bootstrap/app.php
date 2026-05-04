<?php

use App\Http\Middleware\AdminTokenAuth;
use App\Http\Middleware\PandoraJwtAuth;
use App\Http\Middleware\RecordDailyStreak;
use App\Http\Middleware\RequestContextLogger;
use App\Http\Middleware\SanctumOrPandoraJwt;
use App\Http\Middleware\VerifyEcommerceWebhookSignature;
use App\Http\Middleware\VerifyFranchiseeWebhookSignature;
use App\Http\Middleware\VerifyGamificationWebhookSignature;
use App\Http\Middleware\VerifyIdentityWebhookSignature;
use App\Http\Middleware\VerifyLifecycleInvalidateSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Log\Context\Repository as LogContext;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Stamp every API request with request_id + structured log context
        $middleware->prependToGroup('api', RequestContextLogger::class);

        $middleware->alias([
            'admin.token' => AdminTokenAuth::class,
            // SPEC-daily-login-streak — record per-App daily login streak after auth.
            'daily.streak' => RecordDailyStreak::class,
            // ADR-007 Phase 4 — Pandora Core JWT auth (與既有 sanctum 並行；Phase 5+ 才陸續切換)
            'pandora.jwt' => PandoraJwtAuth::class,
            // ADR-007 Phase F prep — 接受 sanctum or platform JWT（route 不動，逐條切換用）
            'auth.dual' => SanctumOrPandoraJwt::class,
            // platform → 朵朵 webhook 簽章驗證
            'identity.webhook' => VerifyIdentityWebhookSignature::class,
            // py-service → 朵朵 gamification webhook 簽章驗證 (ADR-009 §2.2)
            'gamification.webhook' => VerifyGamificationWebhookSignature::class,
            // py-service → 潘朵拉飲食 lifecycle cache invalidate (PG-93)
            'lifecycle.invalidate' => VerifyLifecycleInvalidateSignature::class,
            // 婕樂纖 → 朵朵 ecommerce/order webhook 簽章驗證
            'ecommerce.webhook' => VerifyEcommerceWebhookSignature::class,
            // 母艦 → 朵朵 franchisee 身份同步 webhook (HMAC, 獨立 secret + nonce 表)
            'franchisee.webhook' => VerifyFranchiseeWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Add structured fields to every reported exception so ops can grep
        // by request_id / user / route. Sentry plug-in picks this up via its
        // Laravel integration's ContextRepository (auto-discovered).
        $exceptions->context(function (\Throwable $e) {
            $context = [];
            if (function_exists('app') && app()->bound(LogContext::class)) {
                /** @var LogContext $repo */
                $repo = app(LogContext::class);
                $context = $repo->all();
            }
            $context['exception_class'] = get_class($e);

            return $context;
        });

        // Forward to Sentry (no-op when SENTRY_LARAVEL_DSN unset / APP_ENV not
        // production|staging — see config/sentry.php). The package auto-binds
        // captureUnhandledException via service provider; this is an explicit
        // belt-and-braces reportable() hook for future custom routing.
        $exceptions->reportable(function (\Throwable $e) {
            // Forward only when SDK bound (composer-installed) AND DSN configured.
            // Empty DSN = Sentry self-disables internally; this guard short-circuits earlier.
            if (app()->bound('sentry') && config('sentry.dsn') !== null && config('sentry.dsn') !== '') {
                \Sentry\Laravel\Integration::captureUnhandledException($e);
            }
        });
    })->create();
