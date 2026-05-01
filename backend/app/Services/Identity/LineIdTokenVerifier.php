<?php

namespace App\Services\Identity;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies a LINE Login id_token via LINE's verify endpoint.
 *
 * LINE provides a server-side verify endpoint that signs/validates the token
 * for us in exchange for the channel_id — much simpler than fetching JWKS
 * because LINE rotates frequently and pre-validates iss/aud server-side. We
 * still re-check the iss / sub / exp / iat / aud claims defensively in case
 * LINE relaxes server-side checks one day.
 *
 * Stub mode bypasses the network call so tests can drive the controller; never
 * enable in prod (would let any client forge a line_id).
 */
class LineIdTokenVerifier
{
    private const VERIFY_URL = 'https://api.line.me/oauth2/v2.1/verify';

    private const ISSUER = 'https://access.line.me';

    /**
     * @return array{sub:string, email:?string, name:?string}|null
     */
    public function verify(string $idToken): ?array
    {
        $channelId = (string) config('services.line.channel_id');
        if ($channelId === '') {
            Log::warning('[LineIdTokenVerifier] services.line.channel_id is empty');

            return null;
        }

        if ((bool) config('services.line.stub_mode', false)) {
            return $this->verifyStub($idToken, $channelId);
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'id_token' => $idToken,
                'client_id' => $channelId,
            ]);
            if (! $response->successful()) {
                return null;
            }

            /** @var array<string,mixed> $claims */
            $claims = $response->json() ?: [];

            return $this->normaliseClaims($claims, $channelId);
        } catch (\Throwable $e) {
            Log::info('[LineIdTokenVerifier] verification failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Stub mode: id_token is `header.payload.signature` (any header / signature)
     * with payload = base64url JSON containing required claims.
     *
     * @return array{sub:string, email:?string, name:?string}|null
     */
    private function verifyStub(string $idToken, string $channelId): ?array
    {
        $parts = explode('.', $idToken);
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

        return $this->normaliseClaims($payload, $channelId);
    }

    /**
     * @param  array<string,mixed>  $claims
     * @return array{sub:string, email:?string, name:?string}|null
     */
    private function normaliseClaims(array $claims, string $channelId): ?array
    {
        $iss = $claims['iss'] ?? null;
        $aud = $claims['aud'] ?? null;
        $sub = $claims['sub'] ?? null;
        $exp = $claims['exp'] ?? null;
        $iat = $claims['iat'] ?? null;

        if ($iss !== self::ISSUER) {
            return null;
        }
        if ($aud !== $channelId) {
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

        $email = $claims['email'] ?? null;
        $name = $claims['name'] ?? null;

        return [
            'sub' => $sub,
            'email' => is_string($email) && $email !== '' ? $email : null,
            'name' => is_string($name) && $name !== '' ? $name : null,
        ];
    }
}
