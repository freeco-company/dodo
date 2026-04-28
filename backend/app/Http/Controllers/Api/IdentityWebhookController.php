<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Identity\DodoUserSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 朵朵端 platform webhook receiver — 把 user.upserted 落地到 dodo_users mirror。
 *
 * 跟母艦不同：朵朵只取 minimal identity 欄位（uuid / display_name / avatar / tier），
 * 收到 PII 欄位也忽略 — 強制 ADR-007 §2.3 的禁忌欄位原則。
 *
 * 業務狀態欄位（gamification / health / progression）不從 platform 來，
 * 由朵朵自己擁有；DodoUserSyncService 會在識別到對應 legacy User 時做雙向 mirror。
 */
class IdentityWebhookController extends Controller
{
    public function __construct(private DodoUserSyncService $sync) {}

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

        $user = $this->sync->syncFromPlatform($data['uuid'], $data);

        return response()->json([
            'status' => 'ok',
            'pandora_user_uuid' => $user->pandora_user_uuid,
        ]);
    }
}
