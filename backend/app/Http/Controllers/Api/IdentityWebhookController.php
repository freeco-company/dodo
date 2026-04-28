<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DodoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 朵朵端 platform webhook receiver — 把 user.upserted 落地到 dodo_users mirror。
 *
 * 跟母艦不同：朵朵只取 minimal 欄位（uuid / display_name / avatar / tier），
 * 收到 PII 欄位也忽略 — 強制 ADR-007 §2.3 的禁忌欄位原則。
 */
class IdentityWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $type = (string) $request->input('type', '');
        $data = $request->input('data', []);

        if (! is_array($data) || ! isset($data['uuid']) || ! is_string($data['uuid']) || $data['uuid'] === '') {
            return response()->json(['error' => 'invalid payload: data.uuid missing'], 422);
        }

        if (! in_array($type, ['user.upserted', 'user.suspended', 'user.merged'], true)) {
            Log::warning('[IdentityWebhook] unknown event type', ['type' => $type]);

            return response()->json(['error' => "unknown event type: {$type}"], 422);
        }

        $uuid = $data['uuid'];

        // 嚴格 minimal 欄位：故意不從 payload 撈 email / phone / address；
        // 即使 platform 推來也無視。
        $user = DodoUser::query()->updateOrCreate(
            ['pandora_user_uuid' => $uuid],
            [
                'display_name' => $this->normalizeString($data['display_name'] ?? null, 100),
                'avatar_url' => $this->normalizeString($data['avatar_url'] ?? null, 500),
                'subscription_tier' => $this->normalizeString($data['subscription_tier'] ?? null, 32),
                'last_synced_at' => now(),
            ]
        );

        return response()->json([
            'status' => 'ok',
            'pandora_user_uuid' => $user->pandora_user_uuid,
        ]);
    }

    private function normalizeString(mixed $v, int $maxLen): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);
        if ($v === '') {
            return null;
        }

        return mb_substr($v, 0, $maxLen);
    }
}
