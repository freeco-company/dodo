<?php

namespace App\Services\Identity;

use App\Models\DodoUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\Uuid;

/**
 * Phase C — 把朵朵端「業務狀態」與 platform 的「identity mirror」維持一致。
 *
 * 兩個方向：
 *
 *   1. platform → DodoUser（identity mirror 4 欄位）
 *      由 IdentityWebhookController 收 user.upserted 事件觸發。
 *      只 sync uuid / display_name / avatar_url / subscription_tier。
 *      其他欄位（業務狀態）由朵朵自己擁有，platform 不覆蓋。
 *
 *   2. legacy User ↔ DodoUser（過渡期業務狀態雙向 mirror）
 *      Phase A 既有 sanctum users 表還活著（22 個 services 還在用）。
 *      只要 DodoUser 透過 referral_code / 其他 key 對應得到 legacy User，
 *      就把業務狀態欄位雙向 mirror，讓兩邊 read 都拿到一樣的資料。
 *      這條過渡邏輯在 Phase F drop user_id 之後可以拆掉。
 *
 * 為什麼要雙向 mirror：
 *   - 22 個既有 services 還寫 legacy User（短期不會全改）
 *   - 新接 platform JWT 的 path 會讀 DodoUser
 *   - 沒有 mirror = 同一個人在兩處看到不同 streak / level，使用者觀感破裂
 *
 * 這層在 Phase F「drop user_id + service 全改」後可整支刪除。
 *
 * @see ADR-007 §2.3
 */
class DodoUserSyncService
{
    /**
     * platform 推來的 user.upserted 事件 → 落地 mirror 的 4 個 identity 欄位。
     * 業務狀態欄位 platform 不擁有，這裡不動。
     *
     * @param  array<string,mixed>  $platformPayload  user.upserted 事件 data 區
     */
    public function syncFromPlatform(string $uuid, array $platformPayload): DodoUser
    {
        $mirror = DodoUser::query()->updateOrCreate(
            ['pandora_user_uuid' => $uuid],
            [
                'display_name' => $this->str($platformPayload['display_name'] ?? null, 100),
                'avatar_url' => $this->str($platformPayload['avatar_url'] ?? null, 500),
                'subscription_tier' => $this->str($platformPayload['subscription_tier'] ?? null, 32),
                'last_synced_at' => now(),
            ]
        );

        // 若這個 uuid 已經 link 到 legacy user，順手把 platform tier mirror 回去。
        $legacy = $this->findLegacyByUuid($uuid);
        if ($legacy !== null) {
            $this->mirrorIdentityToLegacy($mirror, $legacy);
        }

        return $mirror->refresh();
    }

    /**
     * Phase D Wave 1 — legacy User 進門時，保證對應的 DodoUser mirror 存在。
     *
     * 觸發時機：UserObserver::saved 自動呼叫；artisan identity:backfill-mirror
     * 對既有 user 也呼叫一次。
     *
     * 行為：
     *   1. legacy User 還沒 pandora_user_uuid → 產一個 UUID v7 寫回 user。
     *      用 v7 而非 v4 的原因：時間有序、index locality 較好（dodo_users PK 是
     *      pandora_user_uuid，CHAR(36)，B-tree insertion 按時間進場成本最低）。
     *      這個 uuid 是「朵朵側發起」的，Phase 4 platform 接上來時，platform
     *      也會以同一個 uuid 為標識（W3 SDK migration 才開始與 platform 雙向）。
     *   2. updateOrCreate DodoUser by uuid。
     *   3. syncBusinessState user → mirror，把 50+ 業務狀態欄位推上去。
     *
     * idempotent — 多次呼叫結果一致：
     *   - 已有 uuid → 不重簽
     *   - 已有 mirror → updateOrCreate 走 update path
     *   - business state 雙向欄位是 overwrite，不會疊加
     */
    public function ensureMirror(User $user): DodoUser
    {
        // step 1: 給 legacy user 釘上 uuid（若還沒有）
        if (empty($user->pandora_user_uuid)) {
            $user->pandora_user_uuid = (string) Uuid::v7();
            $user->save();
        }

        // step 2: updateOrCreate mirror — 不直接灌業務狀態，留給 step 3 統一處理
        $mirror = DodoUser::query()->updateOrCreate(
            ['pandora_user_uuid' => $user->pandora_user_uuid],
            ['last_synced_at' => now()],
        );

        // step 3: legacy User → DodoUser business state mirror
        $this->syncBusinessState($user, $mirror, 'user-to-mirror');

        return $mirror->refresh();
    }

    /**
     * 業務狀態雙向 mirror — 給 service / controller 在寫 legacy User 後呼叫，
     * 把 gamification / health / progression 欄位推到 DodoUser；反向亦然。
     *
     * @param  'user-to-mirror'|'mirror-to-user'  $direction
     */
    public function syncBusinessState(User $legacy, DodoUser $mirror, string $direction = 'user-to-mirror'): void
    {
        $payload = $direction === 'user-to-mirror'
            ? $this->extractBusinessState($legacy)
            : $this->extractBusinessState($mirror);

        $target = $direction === 'user-to-mirror' ? $mirror : $legacy;

        // 過濾掉 target 沒有的欄位（legacy User 沒 last_synced_at；DodoUser 沒 name）
        $payload = array_intersect_key($payload, array_flip($this->fillableOf($target)));

        $target->fill($payload)->save();
    }

    /**
     * 透過 referral_code 把 platform uuid 對到 legacy User。
     *
     * 為什麼用 referral_code 不用 email：朵朵 Phase F 之後 email 不在 mirror，
     * 不能依賴。referral_code 是朵朵獨有 + unique + Phase A 既有，當 join key 最穩。
     * 若沒對應也無妨，下次 webhook 來再嘗試。
     */
    private function findLegacyByUuid(string $uuid): ?User
    {
        $mirror = DodoUser::find($uuid);
        if ($mirror === null || $mirror->referral_code === null) {
            return null;
        }

        return User::query()->where('referral_code', $mirror->referral_code)->first();
    }

    private function mirrorIdentityToLegacy(DodoUser $mirror, User $legacy): void
    {
        $legacy->fill([
            'subscription_tier' => $mirror->subscription_tier,
        ])->save();
    }

    /**
     * 共用業務狀態欄位清單 — 兩邊 model 共有的「朵朵業務狀態」。
     *
     * 不含 identity（display_name / avatar_url / subscription_tier 由 platform 擁有）。
     * 不含 PII（legacy User 雖然有 email / password，DodoUser 永遠不該存）。
     *
     * @return array<string,mixed>
     */
    private function extractBusinessState(Model $source): array
    {
        $fields = [
            // gamification
            'avatar_color', 'avatar_species', 'avatar_animal',
            'daily_pet_count', 'last_pet_date', 'last_gift_date',
            'outfits_owned', 'equipped_outfit',
            'friendship', 'streak_shields', 'shield_last_refill',

            // health
            'height_cm', 'current_weight_kg', 'target_weight_kg', 'start_weight_kg',
            'birth_date', 'gender', 'activity_level',
            'allergies', 'dislike_foods', 'favorite_foods', 'dietary_type',
            'daily_calorie_target', 'daily_protein_target_g', 'daily_water_goal_ml',

            // progression
            'level', 'xp', 'current_streak', 'longest_streak', 'total_days', 'last_active_date',

            // subscription mirror（不含 subscription_tier — 那是 identity 範疇）
            'subscription_expires_at', 'subscription_expires_at_iso', 'subscription_type',
            'membership_tier', 'tier_verified_at', 'fp_ref_code',
            'trial_started_at', 'trial_expires_at',

            // journey
            'island_visits_used', 'island_visits_reset_at',
            'journey_cycle', 'journey_day', 'journey_last_advance_date', 'journey_started_at',

            // app-internal
            'onboarded_at', 'disclaimer_ack_at', 'referral_code', 'push_enabled',

            // deletion
            'deletion_requested_at', 'hard_delete_after',
        ];

        $out = [];
        foreach ($fields as $f) {
            // 用 attribute 而不是 getAttribute()，避免觸發 accessor side effect
            if (array_key_exists($f, $source->getAttributes())) {
                $out[$f] = $source->getAttribute($f);
            }
        }

        return $out;
    }

    /**
     * @return array<int,string>
     */
    private function fillableOf(Model $model): array
    {
        $fillable = $model->getFillable();
        if ($fillable === []) {
            // legacy User 用 #[Fillable] attribute；保險起見從 attributes 補
            return array_keys($model->getAttributes());
        }

        return $fillable;
    }

    private function str(mixed $v, int $maxLen): ?string
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
