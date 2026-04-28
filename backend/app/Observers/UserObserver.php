<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Identity\DodoUserSyncService;
use Illuminate\Support\Facades\Log;

/**
 * Phase D Wave 1 — 任何 legacy User 寫入 (register / login token issue / profile update)
 * 都自動 ensureMirror，讓 DodoUser mirror 永遠跟得上。
 *
 * 為什麼放 saved 而不是 created：
 *   - register / login 之外，profile update（onboarded_at / streak / level）也應該
 *     反映到 mirror。saved 涵蓋 create + update，一個 hook 處理所有路徑。
 *   - created 雖然能解決 register，但 syncBusinessState 在 user 還沒落地的瞬間
 *     抓不到任何狀態（attributes 是 default）；saved 確保資料已寫入。
 *
 * 為什麼包 try / catch + log：
 *   - mirror 失敗不該 break legacy user 寫入（auth flow 不能因為 mirror 掛就 401）。
 *   - 真有問題會在 log 看到，artisan identity:backfill-mirror 也能補。
 *
 * 防遞迴：
 *   - ensureMirror 內部會 user->save()（寫 pandora_user_uuid 回去），
 *     會重新觸發 saved → 進入這個 observer。
 *   - 第二次進來時 user 已經有 uuid，ensureMirror 會走 updateOrCreate 走 update path
 *     回 mirror，但 syncBusinessState 又會 user->save()（什麼欄位都沒改也會 save）。
 *   - 為了避免無窮 recursion，第二次進來時 user->wasChanged() 為 false（business
 *     state 已 mirror 過、attributes 一樣），但 saved event 還是會觸發。
 *   - 用 in-memory flag 標記「這個 model instance 正在 ensure 中」防 reentry。
 *
 * @see App\Services\Identity\DodoUserSyncService::ensureMirror()
 */
class UserObserver
{
    /**
     * 用 SplObjectStorage 標記正在處理中的 User instance，防止 saved 內部
     * ensureMirror 又觸發 saved 造成無窮 recursion。
     *
     * @var \WeakMap<User, true>|null
     */
    private static ?\WeakMap $inFlight = null;

    public function saved(User $user): void
    {
        self::$inFlight ??= new \WeakMap;

        if (isset(self::$inFlight[$user])) {
            return;
        }

        self::$inFlight[$user] = true;
        try {
            app(DodoUserSyncService::class)->ensureMirror($user);
        } catch (\Throwable $e) {
            // mirror 失敗不影響 legacy user 寫入；artisan backfill 可以補
            Log::warning('[UserObserver] ensureMirror failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            unset(self::$inFlight[$user]);
        }
    }
}
