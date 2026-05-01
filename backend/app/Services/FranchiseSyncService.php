<?php

namespace App\Services;

use App\Models\DodoUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 同步使用者「是否為加盟夥伴」狀態 — 母艦 (pandora-js-store) 是 source of truth，
 * 因為加盟資格是 FP 業務 / 後台流程決定的（首單 NT$6,600 / 業務手動標記）。
 *
 * 這個 service 兩個入口：
 *   - markFranchisee()  — 收到 franchisee.activated 事件
 *   - unmarkFranchisee() — 收到 franchisee.deactivated 事件（極少數情境，例：誤標）
 *
 * 對應 User + DodoUser 兩張表（朵朵 mirror 結構）— legacy User 表 fillable 仍有
 * is_franchisee 是為了向後相容 OutfitController / CardService / PokedexController
 * 既有的 $user->is_franchisee 讀取點。DodoUser 是 ADR-007 之後的 canonical mirror。
 */
class FranchiseSyncService
{
    /**
     * @param  array<string,mixed>  $context  optional metadata for logging (source, admin_id, etc.)
     * @return array{matched:bool, pandora_user_uuid:?string}
     */
    public function markFranchisee(string $identifier, IdentifierKind $kind, ?CarbonImmutable $verifiedAt = null, array $context = []): array
    {
        $verifiedAt ??= CarbonImmutable::now();

        return $this->apply($identifier, $kind, true, Carbon::instance($verifiedAt->toDateTime()), $context);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{matched:bool, pandora_user_uuid:?string}
     */
    public function unmarkFranchisee(string $identifier, IdentifierKind $kind, array $context = []): array
    {
        return $this->apply($identifier, $kind, false, null, $context);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{matched:bool, pandora_user_uuid:?string}
     */
    private function apply(string $identifier, IdentifierKind $kind, bool $isFranchisee, ?Carbon $verifiedAt, array $context): array
    {
        // 1) Always look up User (legacy table — has both email + pandora_user_uuid)
        $user = match ($kind) {
            IdentifierKind::Uuid => User::query()->where('pandora_user_uuid', $identifier)->first(),
            IdentifierKind::Email => User::query()->where('email', $identifier)->first(),
        };

        // 2) DodoUser lookup is uuid-only (ADR-007 §2.3 — no PII in mirror)
        $uuidForDodo = match ($kind) {
            IdentifierKind::Uuid => $identifier,
            IdentifierKind::Email => $user?->pandora_user_uuid,
        };
        $dodoUser = is_string($uuidForDodo) && $uuidForDodo !== ''
            ? DodoUser::query()->where('pandora_user_uuid', $uuidForDodo)->first()
            : null;

        if (! $user && ! $dodoUser) {
            Log::warning('[FranchiseSync] no matching user', [
                'kind' => $kind->value,
                'identifier_hash' => sha1($identifier),
            ]);

            return ['matched' => false, 'pandora_user_uuid' => null];
        }

        if ($user) {
            $user->is_franchisee = $isFranchisee;
            $user->franchise_verified_at = $verifiedAt;
            $user->save();
        }
        if ($dodoUser) {
            $dodoUser->is_franchisee = $isFranchisee;
            $dodoUser->franchise_verified_at = $verifiedAt;
            $dodoUser->save();
        }

        $uuid = $user?->pandora_user_uuid ?? $dodoUser?->pandora_user_uuid;
        Log::info($isFranchisee ? '[FranchiseSync] marked franchisee' : '[FranchiseSync] unmarked franchisee', [
            'pandora_user_uuid' => $uuid,
            'kind' => $kind->value,
            'context' => $context,
        ]);

        return ['matched' => true, 'pandora_user_uuid' => $uuid];
    }
}
