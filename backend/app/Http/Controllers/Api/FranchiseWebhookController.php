<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FranchiseSyncService;
use App\Services\IdentifierKind;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 母艦 (pandora-js-store) → 朵朵 加盟身份同步 webhook。
 *
 * 觸發來源（母艦端）：
 *   - 加盟商首單成立 (NT$6,600+) → franchisee.activated
 *   - 業務 / 客服在母艦 admin 手動標記 → franchisee.activated
 *   - 退出 / 誤標還原 → franchisee.deactivated
 *
 * 收到後：朵朵 User + DodoUser 同步寫入 is_franchisee + franchise_verified_at。
 * 失配（uuid/email 在朵朵未存在）→ 200 unmatched，讓 publisher 不重試（這是正常情況：
 * 該用戶尚未下載朵朵 App）。
 */
class FranchiseWebhookController extends Controller
{
    public function __construct(private FranchiseSyncService $sync) {}

    public function __invoke(Request $request): JsonResponse
    {
        $type = (string) $request->input('type', '');
        $data = $request->input('data', []);

        if (! is_array($data)) {
            return response()->json(['error' => 'invalid payload: data must be object'], 422);
        }

        if (! in_array($type, ['franchisee.activated', 'franchisee.deactivated'], true)) {
            return response()->json(['error' => "unknown event type: {$type}"], 422);
        }

        // Identifier resolution — uuid preferred, email fallback for users
        // who haven't yet been mirrored via Pandora Core identity sync.
        $uuid = is_string($data['uuid'] ?? null) ? $data['uuid'] : '';
        $email = is_string($data['email'] ?? null) ? $data['email'] : '';
        if ($uuid !== '') {
            $identifier = $uuid;
            $kind = IdentifierKind::Uuid;
        } elseif ($email !== '') {
            $identifier = $email;
            $kind = IdentifierKind::Email;
        } else {
            return response()->json(['error' => 'invalid payload: data.uuid or data.email required'], 422);
        }

        $context = [
            'source' => is_string($data['source'] ?? null) ? $data['source'] : 'unknown',
        ];

        if ($type === 'franchisee.activated') {
            $verifiedAtRaw = is_string($data['verified_at'] ?? null) ? $data['verified_at'] : null;
            $verifiedAt = $verifiedAtRaw !== null ? CarbonImmutable::parse($verifiedAtRaw) : null;
            $result = $this->sync->markFranchisee($identifier, $kind, $verifiedAt, $context);
        } else {
            $result = $this->sync->unmarkFranchisee($identifier, $kind, $context);
        }

        return response()->json([
            'status' => $result['matched'] ? 'ok' : 'unmatched',
            'pandora_user_uuid' => $result['pandora_user_uuid'],
        ]);
    }
}
