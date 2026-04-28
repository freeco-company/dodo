<?php

namespace App\Http\Middleware;

use App\Services\Identity\IdentityClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4 (ADR-007 §2.3 / pandora-core-identity#11)：朵朵 API 用 platform JWT
 * 認證的 middleware。
 *
 * 流程：
 *   1. 拿 Authorization: Bearer header
 *   2. 過 PlatformJwtVerifier（RS256 / iss / aud / exp / nbf）
 *   3. 從 sub claim 取 UUID v7
 *   4. firstOrCreate DodoUser mirror
 *   5. attach 到 $request->attributes 給 controller 用
 *
 * 失敗回 401，不 fall through（不像 sanctum 那樣 optional）。
 *
 * 此 middleware 為 Phase 5+ 路徑遷移而生；Phase A 既有 sanctum 中介層仍存在
 * 並保留所有現有 routes，兩者並行。
 */
class PandoraJwtAuth
{
    public function __construct(private IdentityClient $client) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $this->extractBearer($request);
        if ($bearer === null) {
            return response()->json(['error' => 'missing bearer token'], 401);
        }

        $resolved = $this->client->resolveFromJwt($bearer);
        if ($resolved === null) {
            return response()->json(['error' => 'invalid token'], 401);
        }

        $request->attributes->set('pandora_user', $resolved['user']);
        $request->attributes->set('pandora_jwt', $resolved['token']);

        return $next($request);
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
