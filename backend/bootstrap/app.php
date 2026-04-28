<?php

use App\Http\Middleware\AdminTokenAuth;
use App\Http\Middleware\PandoraJwtAuth;
use App\Http\Middleware\SanctumOrPandoraJwt;
use App\Http\Middleware\VerifyIdentityWebhookSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.token' => AdminTokenAuth::class,
            // ADR-007 Phase 4 — Pandora Core JWT auth (與既有 sanctum 並行；Phase 5+ 才陸續切換)
            'pandora.jwt' => PandoraJwtAuth::class,
            // ADR-007 Phase F prep — 接受 sanctum or platform JWT（route 不動，逐條切換用）
            'auth.dual' => SanctumOrPandoraJwt::class,
            // platform → 朵朵 webhook 簽章驗證
            'identity.webhook' => VerifyIdentityWebhookSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
