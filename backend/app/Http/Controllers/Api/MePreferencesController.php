<?php

namespace App\Http\Controllers\Api;

use App\Events\UserOptedOutFranchiseCta;
use App\Http\Controllers\Controller;
use App\Models\DodoUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 使用者偏好設定 — 目前只有「對加盟方案不感興趣」的 silence toggle。
 *
 * 為什麼新檔不掛在 AccountController：
 *   - AccountController 處理刪除 / 還原帳號（destructive ops）；preference toggle
 *     是 high-volume / non-destructive，混在一起會讓 routing surface 失焦。
 */
class MePreferencesController extends Controller
{
    public function franchiseCtaSilence(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'silenced' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $uuid = $user?->pandora_user_uuid;
        if (! is_string($uuid) || $uuid === '') {
            // 認證但還沒 mirror — 視為 noop，避免暴露 internal state
            return response()->json(['silenced' => (bool) $validated['silenced']]);
        }

        $silenced = (bool) $validated['silenced'];

        // dodo_users mirror 應由 UserObserver 在 User 寫入時自動建好；
        // 防呆：找不到就建，避免 race condition 讓 toggle 丟掉。
        $dodoUser = DodoUser::query()->firstOrNew(['pandora_user_uuid' => $uuid]);
        $dodoUser->franchise_cta_silenced = $silenced;
        $dodoUser->franchise_cta_silenced_at = $silenced ? now() : null;
        $dodoUser->save();

        // 廣播 event：listener 會把 inbox 中該 user 還沒接觸的 lead 標 silenced，
        // BD 之後查 inbox 就會看到「這位主動 opt-out」的標記。
        event(new UserOptedOutFranchiseCta(
            pandoraUserUuid: $uuid,
            silenced: $silenced,
        ));

        return response()->json([
            'silenced' => $silenced,
            'silenced_at' => $dodoUser->franchise_cta_silenced_at?->toIso8601String(),
        ]);
    }
}
