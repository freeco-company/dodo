<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Translated (slimmed) from ai-game/src/services/notifications/push.ts.
 *
 * Currently only manages push_tokens table. Real FCM dispatch is TODO —
 * once FCM_SERVICE_ACCOUNT_JSON is wired, sendPush() will pull tokens
 * from this table and dispatch via FCM HTTP v1.
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
}
