<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin token middleware: requires X-Admin-Token header == config('app.admin_token').
 * Used for cron / ops endpoints (purge expired accounts, flush analytics, SEO admin).
 *
 * If config value is null (DODO_ADMIN_TOKEN unset), all requests are denied — fail
 * closed so accidentally-deployed envs without the secret can't be hit.
 */
class AdminTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('app.admin_token');
        $provided = $request->header('X-Admin-Token');

        if (empty($expected) || ! is_string($provided) || ! hash_equals((string) $expected, $provided)) {
            abort(403, 'INVALID_ADMIN_TOKEN');
        }

        return $next($request);
    }
}
