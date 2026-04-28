<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Translated (slimmed) from ai-game/src/services/notifications/push.ts.
 *
 * Phase E:
 *   - register/unregister: unchanged.
 *   - send(): pulls active tokens, dispatches via FCM HTTP v1
 *     (https://fcm.googleapis.com/v1/projects/{project}/messages:send).
 *     Without FCM_SERVICE_ACCOUNT_JSON we log + skip — token registration
 *     keeps working so the App's permission flow is unaffected.
 *
 * Auth flow note: FCM v1 requires an OAuth2 access_token from the service
 * account (RS256 JWT → Google token endpoint). To keep this PR free of new
 * composer deps we implement the JWT signing inline using openssl_sign;
 * once we add `firebase/php-jwt` or `kreait/firebase-php` we can rip this
 * out. The signed-JWT helper is unit-testable separately.
 */
class PushService
{
    public const PLATFORMS = ['ios', 'android', 'web'];

    /** @param array<string,mixed>|null $deviceInfo */
    public function register(User $user, string $platform, string $token, ?array $deviceInfo = null): int
    {
        $now = now();
        // MariaDB upsert: composite unique (platform, token). Re-registration moves
        // the token to the new user and clears any disabled flag.
        DB::table('push_tokens')->upsert(
            [[
                'user_id' => $user->id,
                // Phase D Wave 1 dual-write
                'pandora_user_uuid' => $user->pandora_user_uuid,
                'platform' => $platform,
                'token' => $token,
                'device_info' => $deviceInfo ? json_encode($deviceInfo, JSON_UNESCAPED_UNICODE) : null,
                'registered_at' => $now,
                'last_seen_at' => $now,
                'disabled_at' => null,
            ]],
            ['platform', 'token'],
            ['user_id', 'pandora_user_uuid', 'device_info', 'last_seen_at', 'disabled_at']
        );

        $row = DB::table('push_tokens')
            ->where('platform', $platform)
            ->where('token', $token)
            ->first(['id']);

        return (int) $row->id;
    }

    public function unregister(string $platform, string $token): void
    {
        DB::table('push_tokens')
            ->where('platform', $platform)
            ->where('token', $token)
            ->delete();
    }

    /**
     * Send a push to a user's active devices. Returns ['sent' => N, 'skipped' => M].
     *
     * @param  array<string, mixed>  $data
     * @return array{sent: int, skipped: int, reason?: string}
     */
    public function send(User $user, string $title, string $body, array $data = []): array
    {
        $serviceAccount = (string) config('services.fcm.service_account_json');
        $projectId = (string) config('services.fcm.project_id');

        $tokens = DB::table('push_tokens')
            ->where('user_id', $user->id)
            ->whereNull('disabled_at')
            ->whereIn('platform', ['ios', 'android'])
            ->pluck('token')
            ->all();

        if (empty($tokens)) {
            return ['sent' => 0, 'skipped' => 0, 'reason' => 'no_tokens'];
        }

        if ($serviceAccount === '' || $projectId === '') {
            Log::info('[push] FCM not configured → skipping send', [
                'user_id' => $user->id,
                'token_count' => count($tokens),
            ]);

            return ['sent' => 0, 'skipped' => count($tokens), 'reason' => 'fcm_not_configured'];
        }

        $accessToken = $this->fetchAccessToken($serviceAccount);
        if (! $accessToken) {
            return ['sent' => 0, 'skipped' => count($tokens), 'reason' => 'fcm_auth_failed'];
        }

        $sent = 0;
        $endpoint = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $dryRun = (bool) config('services.fcm.dry_run');

        foreach ($tokens as $token) {
            $payload = [
                'message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => array_map(fn ($v) => (string) $v, $data),
                ],
            ];
            if ($dryRun) {
                $payload['validate_only'] = true;
            }
            try {
                $resp = Http::withToken($accessToken)
                    ->asJson()
                    ->timeout(5)
                    ->post($endpoint, $payload);
                if ($resp->successful()) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                Log::warning('[push] FCM send error', ['error' => $e->getMessage()]);
            }
        }

        return ['sent' => $sent, 'skipped' => count($tokens) - $sent];
    }

    /**
     * Mint a Google OAuth2 access token from the service-account JSON. Cached
     * for the duration of the request only (caller is rare and short-lived).
     *
     * Real production should cache this in Redis for ~50min (token lifetime
     * is 1h). Phase E keeps it simple.
     */
    private function fetchAccessToken(string $serviceAccountPathOrJson): ?string
    {
        try {
            $json = is_file($serviceAccountPathOrJson)
                ? (string) file_get_contents($serviceAccountPathOrJson)
                : $serviceAccountPathOrJson;
            /** @var array<string, mixed>|null $sa */
            $sa = json_decode($json, true);
            if (! is_array($sa) || ! isset($sa['client_email'], $sa['private_key'], $sa['token_uri'])) {
                Log::warning('[push] FCM service-account JSON malformed');

                return null;
            }

            $now = time();
            $jwtHeader = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']) ?: '');
            $jwtClaim = $this->b64url(json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $sa['token_uri'],
                'iat' => $now,
                'exp' => $now + 3600,
            ]) ?: '');
            $signingInput = "{$jwtHeader}.{$jwtClaim}";
            $signature = '';
            $ok = openssl_sign($signingInput, $signature, (string) $sa['private_key'], OPENSSL_ALGO_SHA256);
            if (! $ok) {
                return null;
            }
            $jwt = "{$signingInput}.".$this->b64url($signature);

            $resp = Http::asForm()->timeout(5)->post((string) $sa['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);
            if (! $resp->successful()) {
                Log::warning('[push] FCM token endpoint non-2xx', ['status' => $resp->status()]);

                return null;
            }

            return (string) ($resp->json('access_token') ?: null);
        } catch (\Throwable $e) {
            Log::warning('[push] FCM token fetch error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function b64url(string $input): string
    {
        return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
    }
}
