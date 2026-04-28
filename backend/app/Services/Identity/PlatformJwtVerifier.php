<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lcobucci\Clock\SystemClock;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;

/**
 * 驗 platform 簽出來的 RS256 JWT。
 *
 * Public key 從 platform `/api/v1/auth/public-key` 抓回來，cache 1 hour。
 * platform key rotation 時 cache 過期會自動拉新；緊急情況可手動 cache:clear。
 *
 * 驗證內容（lcobucci/jwt constraints）：
 *   - issuer (iss)：platform 標識
 *   - audience (aud)：'dodo'
 *   - exp / nbf / iat：標準時間 claims（由 SystemClock + StrictValidAt 處理）
 *   - signature：RS256
 *
 * 失敗回 null，呼叫端自行決定 401 / 403 / fallback。
 */
class PlatformJwtVerifier
{
    private const PUBLIC_KEY_CACHE_KEY = 'identity:platform_jwk';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @return ?Plain 驗章成功的 token（含 claims）；失敗回 null
     */
    public function verify(string $jwt): ?Plain
    {
        $publicKey = $this->getPublicKey();
        if ($publicKey === null) {
            Log::warning('[IdentityClient] cannot fetch platform public key');

            return null;
        }

        try {
            $signer = new Sha256;
            $verificationKey = InMemory::plainText($publicKey);
            $config = Configuration::forAsymmetricSigner(
                $signer,
                InMemory::plainText('not-used-for-verify'),
                $verificationKey,
            );

            /** @var Plain $token */
            $token = $config->parser()->parse($jwt);

            $validator = new Validator;
            $validator->assert(
                $token,
                new SignedWith($signer, $verificationKey),
                new IssuedBy((string) config('services.pandora_core.jwt_issuer', 'pandora-core-identity')),
                new PermittedFor((string) config('services.pandora_core.jwt_audience', 'dodo')),
                new StrictValidAt(SystemClock::fromUTC()),
            );

            return $token;
        } catch (\Throwable $e) {
            Log::info('[IdentityClient] JWT verification failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function getPublicKey(): ?string
    {
        return Cache::remember(self::PUBLIC_KEY_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): ?string {
            $base = (string) config('services.pandora_core.base_url');
            if ($base === '') {
                return null;
            }

            try {
                $response = Http::timeout(5)->get(rtrim($base, '/').'/api/v1/auth/public-key');
                if (! $response->successful()) {
                    return null;
                }

                $key = $response->json('public_key') ?? $response->json('key');

                return is_string($key) && $key !== '' ? $key : null;
            } catch (\Throwable $e) {
                Log::warning('[IdentityClient] failed to fetch public key', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }
}
