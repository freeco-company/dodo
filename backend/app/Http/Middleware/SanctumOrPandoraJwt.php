<?php

namespace App\Http\Middleware;

use App\Services\Identity\IdentityClient;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase F prep（ADR-007 §2.3）：dual-auth middleware — 接受 Pandora platform JWT
 * **或** Laravel Sanctum personal-access token，逐 route 切換 Phase 5 時用。
 *
 * 解析順序：
 *   1. 先試 platform JWT（IdentityClient::resolveFromJwt）
 *      - 成功：把 DodoUser mirror 設為 auth user，request attribute 也帶上
 *        platform claims（給未來需要 scopes 的 controller）
 *   2. 失敗則 fallback sanctum guard
 *      - 成功：用既有 Sanctum 路徑（auth('sanctum')->user()）
 *   3. 都失敗 → 401
 *
 * **重要**：此 middleware 透過 alias `auth:dual` 對外。本 PR 只提供 alias，
 * routes 暫不切；Phase F 會逐 route 改 `auth:sanctum` -> `auth:dual` -> 最後變
 * `pandora.jwt`（純 JWT）。
 */
class SanctumOrPandoraJwt
{
    public function __construct(
        private IdentityClient $identity,
        private AuthFactory $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // (1) Try platform JWT first.
        $bearer = $this->extractBearer($request);
        if ($bearer !== null) {
            $resolved = $this->identity->resolveFromJwt($bearer);
            if ($resolved !== null) {
                $dodoUser = $resolved['user'];
                $request->setUserResolver(static fn () => $dodoUser);
                // Stash claims for downstream controllers that may need scopes.
                $request->attributes->set('pandora_user', $dodoUser);
                $request->attributes->set('pandora_jwt', $resolved['token']);
                $request->attributes->set('auth_strategy', 'pandora_jwt');

                return $next($request);
            }
        }

        // (2) Fallback to sanctum guard.
        $sanctumGuard = $this->auth->guard('sanctum');
        if ($sanctumGuard->check()) {
            $user = $sanctumGuard->user();
            $request->setUserResolver(static fn () => $user);
            $request->attributes->set('auth_strategy', 'sanctum');

            return $next($request);
        }

        // (3) Both failed.
        throw new AuthenticationException('Unauthenticated.', ['sanctum']);
    }

    private function extractBearer(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');
        if (! str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
