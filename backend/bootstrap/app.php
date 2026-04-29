<?php

use App\Http\Middleware\AdminTokenAuth;
use App\Http\Middleware\PandoraJwtAuth;
use App\Http\Middleware\RequestContextLogger;
use App\Http\Middleware\SanctumOrPandoraJwt;
use App\Http\Middleware\VerifyGamificationWebhookSignature;
use App\Http\Middleware\VerifyIdentityWebhookSignature;
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
            // ADR-007 Phase 4 — Pandora Core JWT auth (與既有 sanctum 並行；Phase 5+ 才陸續切換)
            'pandora.jwt' => PandoraJwtAuth::class,
            // ADR-007 Phase F prep — 接受 sanctum or platform JWT（route 不動，逐條切換用）
            'auth.dual' => SanctumOrPandoraJwt::class,
            // platform → 朵朵 webhook 簽章驗證
            'identity.webhook' => VerifyIdentityWebhookSignature::class,
            // py-service → 朵朵 gamification webhook 簽章驗證 (ADR-009 §2.2)
            'gamification.webhook' => VerifyGamificationWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Add structured fields to every reported exception so ops can grep
        // by request_id / user / route. Sentry plug-in (when wired) can
        // pick this up via its Laravel integration's ContextRepository.
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
    })->create();
