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
 * Verifies a Sign in with Apple identity_token (RS256 JWT signed by Apple).
 *
 * Why a separate verifier from PlatformJwtVerifier:
 *   - Apple's JWKS is multi-key + kid-indexed — caller must look up the correct
 *     pubkey from the token's `kid` header, not just trust a single key.
 *   - Apple rotates keys without notice. We cache the JWKS for 1 hour and on
 *     `kid` miss we force-refresh once before failing closed.
 *
 * Stub mode (services.apple.stub_mode=true) bypasses signature + JWKS so tests
 * can drive the controller with a hand-built unsigned payload — never enable in
 * prod (would let any client forge an apple_id).
 */
class AppleIdTokenVerifier
{
    private const APPLE_KEYS_URL = 'https://appleid.apple.com/auth/keys';

    private const JWKS_CACHE_KEY = 'identity:apple_jwks';

    private const CACHE_TTL_SECONDS = 3600;

    private const ISSUER = 'https://appleid.apple.com';

    /**
     * @return array{sub:string, email:?string, email_verified:bool}|null
     */
    public function verify(string $identityToken): ?array
    {
        $clientId = (string) config('services.apple.client_id');
        if ($clientId === '') {
            Log::warning('[AppleIdTokenVerifier] services.apple.client_id is empty');

            return null;
        }

        if ((bool) config('services.apple.stub_mode', false)) {
            return $this->verifyStub($identityToken, $clientId);
        }

        try {
            $parts = explode('.', $identityToken);
            if (count($parts) !== 3) {
                return null;
            }
            $headerJson = base64_decode(strtr($parts[0], '-_', '+/'), true);
            if ($headerJson === false) {
                return null;
            }
            /** @var array<string,mixed> $header */
            $header = json_decode($headerJson, true) ?: [];
            $kid = is_string($header['kid'] ?? null) ? $header['kid'] : null;
            if ($kid === null) {
                return null;
            }

            $pem = $this->resolveKey($kid, refresh: false);
            if ($pem === null) {
                // Possible Apple key rotation — force one refresh before giving up.
                $pem = $this->resolveKey($kid, refresh: true);
            }
            if ($pem === null) {
                Log::warning('[AppleIdTokenVerifier] no JWKS entry for kid', ['kid' => $kid]);

                return null;
            }

            $signer = new Sha256;
            $verificationKey = InMemory::plainText($pem);
            $config = Configuration::forAsymmetricSigner(
                $signer,
                InMemory::plainText('not-used-for-verify'),
                $verificationKey,
            );

            /** @var Plain $token */
            $token = $config->parser()->parse($identityToken);

            (new Validator)->assert(
                $token,
                new SignedWith($signer, $verificationKey),
                new IssuedBy(self::ISSUER),
                new PermittedFor($clientId),
                new StrictValidAt(SystemClock::fromUTC()),
            );

            $claims = $token->claims();
            $sub = (string) $claims->get('sub', '');
            if ($sub === '') {
                return null;
            }

            $email = $claims->get('email');
            $emailVerified = $claims->get('email_verified');

            return [
                'sub' => $sub,
                'email' => is_string($email) && $email !== '' ? $email : null,
                'email_verified' => filter_var($emailVerified, FILTER_VALIDATE_BOOLEAN),
            ];
        } catch (\Throwable $e) {
            Log::info('[AppleIdTokenVerifier] verification failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Stub mode for tests / local dev. Accepts a base64url-encoded JSON payload
     * placed in the middle segment of a `header.payload.signature` shape (so
     * the controller hits the same parse path as production). Required claims:
     *   { "iss": "...", "aud": "...", "sub": "...", "exp": <future>, "iat": <past> }
     *
     * @return array{sub:string, email:?string, email_verified:bool}|null
     */
    private function verifyStub(string $identityToken, string $clientId): ?array
    {
        $parts = explode('.', $identityToken);
        if (count($parts) !== 3) {
            return null;
        }
        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($payloadJson === false) {
            return null;
        }
        /** @var array<string,mixed>|null $payload */
        $payload = json_decode($payloadJson, true);
        if (! is_array($payload)) {
            return null;
        }

        $iss = $payload['iss'] ?? null;
        $aud = $payload['aud'] ?? null;
        $sub = $payload['sub'] ?? null;
        $exp = $payload['exp'] ?? null;
        $iat = $payload['iat'] ?? null;

        if ($iss !== self::ISSUER) {
            return null;
        }
        if ($aud !== $clientId) {
            return null;
        }
        if (! is_string($sub) || $sub === '') {
            return null;
        }
        if (! is_int($exp) || $exp < time()) {
            return null;
        }
        if (! is_int($iat) || $iat > time() + 60) {
            return null;
        }

        $email = $payload['email'] ?? null;

        return [
            'sub' => $sub,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'email_verified' => filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function resolveKey(string $kid, bool $refresh): ?string
    {
        if ($refresh) {
            Cache::forget(self::JWKS_CACHE_KEY);
        }

        /** @var array<string,string> $byKid */
        $byKid = Cache::remember(self::JWKS_CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            try {
                $response = Http::timeout(5)->get(self::APPLE_KEYS_URL);
                if (! $response->successful()) {
                    return [];
                }
                $keys = $response->json('keys');
                if (! is_array($keys)) {
                    return [];
                }
                $out = [];
                foreach ($keys as $jwk) {
                    if (! is_array($jwk)) {
                        continue;
                    }
                    $kid = $jwk['kid'] ?? null;
                    $n = $jwk['n'] ?? null;
                    $e = $jwk['e'] ?? null;
                    if (! is_string($kid) || ! is_string($n) || ! is_string($e)) {
                        continue;
                    }
                    $pem = $this->jwkRsaToPem($n, $e);
                    if ($pem !== null) {
                        $out[$kid] = $pem;
                    }
                }

                return $out;
            } catch (\Throwable $e) {
                Log::warning('[AppleIdTokenVerifier] failed to fetch JWKS', ['error' => $e->getMessage()]);

                return [];
            }
        });

        return $byKid[$kid] ?? null;
    }

    /**
     * Convert a JWK (RSA n,e in base64url) to a PEM SPKI public key.
     * Implementation: hand-build the DER for `RSAPublicKey` then wrap with
     * SubjectPublicKeyInfo (rsaEncryption OID). Avoids pulling another lib in.
     */
    private function jwkRsaToPem(string $nB64Url, string $eB64Url): ?string
    {
        $n = base64_decode(strtr($nB64Url, '-_', '+/'), true);
        $e = base64_decode(strtr($eB64Url, '-_', '+/'), true);
        if ($n === false || $e === false) {
            return null;
        }

        $modulus = "\x00".$n; // leading 0 to mark unsigned positive integer
        $exponent = (ord($e[0]) > 0x7F) ? "\x00".$e : $e;

        $rsaPublicKey = $this->derSequence(
            $this->derInteger($modulus).$this->derInteger($exponent)
        );

        // AlgorithmIdentifier: rsaEncryption OID = 1.2.840.113549.1.1.1
        $algoOid = "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01";
        $algoSeq = $this->derSequence($algoOid."\x05\x00"); // NULL params

        // BIT STRING wraps the RSAPublicKey DER, with leading 0 for unused bits.
        $bitString = "\x03".$this->derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;

        $spki = $this->derSequence($algoSeq.$bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----';

        return $pem;
    }

    private function derSequence(string $contents): string
    {
        return "\x30".$this->derLength(strlen($contents)).$contents;
    }

    private function derInteger(string $contents): string
    {
        return "\x02".$this->derLength(strlen($contents)).$contents;
    }

    private function derLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }
        $bytes = '';
        while ($len > 0) {
            $bytes = chr($len & 0xFF).$bytes;
            $len >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }
}
