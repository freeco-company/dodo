<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamp every request with a stable `X-Request-Id` and bind structured fields
 * onto Laravel's log context so any subsequent `Log::error()` / `Log::warning()`
 * within the request lifecycle automatically inherits them.
 *
 * Why: ops grepping prod logs for a single user's failure case had no anchor —
 * 50 lines from a busy endpoint were indistinguishable. With this middleware
 * each line carries `request_id`, `user_id`, `pandora_user_uuid`, `route`, so
 * `jq 'select(.context.request_id == "...")'` walks one request end-to-end.
 *
 * Future Sentry plug-in: when sentry/sentry-laravel is installed, set
 * `Sentry::configureScope()` here to push the same fields. No code changes
 * elsewhere. Until then, structured laravel logs (`'log_channel' => 'stderr'`
 * + JSON formatter) are what ops greps.
 */
class RequestContextLogger
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->headers->get('X-Request-Id') ?? Str::uuid());

        $context = [
            'request_id' => $requestId,
            'route' => $request->method().' '.($request->route()?->uri() ?? $request->path()),
            'ip' => $request->ip(),
        ];
        $user = $request->user();
        if ($user) {
            $context['user_id'] = $user->getKey();
            // pandora_user_uuid lives on User; tolerate other guards.
            $uuid = $user->pandora_user_uuid ?? null;
            if ($uuid) {
                $context['pandora_user_uuid'] = (string) $uuid;
            }
        }

        Log::shareContext($context);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
