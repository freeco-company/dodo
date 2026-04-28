<?php

namespace App\Services\Identity;

use App\Models\DodoUser;
use Lcobucci\JWT\Token\Plain;

/**
 * 朵朵端對 platform 的 facade。
 *
 * 提供：
 *   - findOrCreateMirror(uuid, ...): 取本地 mirror，沒有則建立 stub（讓 webhook
 *     之後填血肉）
 *   - resolveFromJwt($token): 驗 JWT + 取 mirror
 *
 * 嚴守 ADR-007 §2.3：API 不暴露任何 PII helper（沒有 fetchUserPII()）— 朵朵端
 * 應用層拿不到 PII，需要時呼叫 platform 的 GET /api/v1/users/{uuid}（未實作，
 * Phase 5 才會接）。本 PR 只搞定身份驗證 + minimal mirror。
 */
class IdentityClient
{
    public function __construct(private PlatformJwtVerifier $verifier) {}

    /**
     * 從 Authorization: Bearer header 拿 JWT 驗章 + 取 mirror。
     *
     * @return ?array{token: Plain, user: DodoUser}
     */
    public function resolveFromJwt(string $bearerToken): ?array
    {
        $verified = $this->verifier->verify($bearerToken);
        if ($verified === null) {
            return null;
        }

        $uuid = $verified->claims()->get('sub');
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        $user = $this->findOrCreateMirror($uuid);

        return ['token' => $verified, 'user' => $user];
    }

    /**
     * 確保本地有一筆 dodo_users (uuid)，沒有則建 stub。display_name 等欄位
     * 由 webhook 之後填入。同步上線之前，這裡建出來的會是 placeholder。
     */
    public function findOrCreateMirror(string $uuid): DodoUser
    {
        return DodoUser::query()->firstOrCreate(['pandora_user_uuid' => $uuid]);
    }
}
