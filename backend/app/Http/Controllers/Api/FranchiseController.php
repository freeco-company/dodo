<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Conversion\ConversionEventPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 加盟漏斗 CTA 事件 receiver（ADR-003 §2.3）。
 *
 * 兩個 endpoint：
 *   POST /api/franchise/cta-view  — 前端在 CTA banner 顯示時 fire（漏斗分母）
 *   POST /api/franchise/cta-click — 前端在點擊 CTA 時 fire（loyalist→applicant trigger）
 *
 * 兩者皆需登入；publisher 內部會處理 uuid 缺失 / py-service 未配置等 fallback。
 *
 * 設計上「為什麼前端透過 dodo backend 而非直接打 py-service」：
 *   - py-service /events 端點要 platform JWT，朵朵前端目前拿到的是 sanctum token
 *   - 透過 dodo backend 用 HMAC publish 統一 auth 模型（Phase 5 拿到 user JWT 之後可改 direct）
 */
class FranchiseController extends Controller
{
    public function __construct(
        private readonly ConversionEventPublisher $conversion,
    ) {}

    public function ctaView(Request $request): JsonResponse
    {
        return $this->fire($request, 'franchise.cta_view');
    }

    public function ctaClick(Request $request): JsonResponse
    {
        return $this->fire($request, 'franchise.cta_click');
    }

    private function fire(Request $request, string $eventType): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['nullable', 'string', 'max:64'],
            'content_id' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $request->user();
        $uuid = $user?->pandora_user_uuid;
        if (! is_string($uuid) || $uuid === '') {
            // Authenticated but no uuid mirror yet (pre Phase D Wave 1) — accept
            // request silently to keep client UX smooth; publisher would noop anyway.
            return response()->json(['status' => 'accepted']);
        }

        $this->conversion->publish($uuid, $eventType, [
            'source' => $validated['source'] ?? null,
            'content_id' => $validated['content_id'] ?? null,
        ]);

        return response()->json(['status' => 'accepted']);
    }
}
