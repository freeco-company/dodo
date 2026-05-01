<?php

namespace App\Services;

use App\Models\DodoUser;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Translated from ai-game/src/services/account_deletion.ts.
 *
 * Apple App Store Guideline 5.1.1(v) requires in-app deletion. Also satisfies
 * 個資法 right-to-erasure.
 *
 * Two-phase to avoid accidental loss:
 *   1. request()   → soft flag + 7-day cooldown
 *   2. purge()     → hard DELETE after cooldown elapses (cron)
 */
class AccountDeletionService
{
    public const COOLDOWN_DAYS = 7;

    /** @return array{hard_delete_after:string} */
    public function request(User $user): array
    {
        $now = Carbon::now();
        $hardDeleteAfter = $now->copy()->addDays(self::COOLDOWN_DAYS);
        $user->deletion_requested_at = $now;
        $user->hard_delete_after = $hardDeleteAfter;
        $user->save();
        return ['hard_delete_after' => $hardDeleteAfter->toIso8601String()];
    }

    public function restore(User $user): bool
    {
        if (! $user->deletion_requested_at) return false;
        if ($user->hard_delete_after && $user->hard_delete_after->isPast()) return false;
        $user->deletion_requested_at = null;
        $user->hard_delete_after = null;
        $user->save();
        return true;
    }

    /**
     * Cron: hard-delete users whose cooldown has elapsed.
     *
     * Apple App Store Guideline 5.1.1(v) requires that calling delete actually
     * wipes user data — soft cascade isn't enough. Two reasons we explicitly
     * purge `dodo_users` here instead of relying on FK CASCADE:
     *
     *   1. The reference-table migrations (ADR-007 §2.3) intentionally do NOT
     *      create FKs from business rows → dodo_users (loose coupling so the
     *      mirror can lag the SoT during identity backfill). Cascade therefore
     *      doesn't reach the mirror row from a `users` delete.
     *   2. Belt-and-braces — even when we add FKs in Phase F we want the
     *      explicit delete so audit logs show *what we wiped* not just
     *      *what the DB cleaned up*.
     *
     * @todo ADR-007 §2.3 SoT — once `IdentityClient` is wired, also call
     *   `POST /v1/internal/users/{uuid}/delete` on Pandora Core so the
     *   group-level identity row is upstream-deleted (right-to-erasure).
     *   Today Pandora Core doesn't yet expose that endpoint.
     */
    public function purge(): int
    {
        $users = User::whereNotNull('hard_delete_after')
            ->where('hard_delete_after', '<', Carbon::now())
            ->get(['id', 'pandora_user_uuid', 'apple_id', 'line_id']);

        if ($users->isEmpty()) {
            return 0;
        }

        $uuids = $users->pluck('pandora_user_uuid')->filter()->all();

        // Trial-fraud guard: copy OAuth provider ids to oauth_trial_blacklist
        // before wiping the user row so a re-registration via the same Apple /
        // LINE id can't mint a fresh 7-day trial. See TrialService::start.
        $blacklistRows = [];
        $now = Carbon::now();
        foreach ($users as $u) {
            if (! empty($u->apple_id)) {
                $blacklistRows[] = [
                    'provider' => 'apple',
                    'provider_sub' => $u->apple_id,
                    'blacklisted_at' => $now,
                    'reason' => 'account_deleted',
                ];
            }
            if (! empty($u->line_id)) {
                $blacklistRows[] = [
                    'provider' => 'line',
                    'provider_sub' => $u->line_id,
                    'blacklisted_at' => $now,
                    'reason' => 'account_deleted',
                ];
            }
        }

        return DB::transaction(function () use ($users, $uuids, $blacklistRows) {
            if (! empty($blacklistRows)) {
                // upsert avoids unique-key violation if same provider_sub somehow
                // re-enters the pipeline (e.g. user re-registers, deletes again).
                DB::table('oauth_trial_blacklist')->upsert(
                    $blacklistRows,
                    ['provider', 'provider_sub'],
                    ['blacklisted_at', 'reason'],
                );
            }

            // Wipe the dodo_users mirror first; if CASCADE is added later this
            // still works (no-op on already-deleted rows).
            if (! empty($uuids)) {
                DodoUser::whereIn('pandora_user_uuid', $uuids)->delete();
            }

            return User::whereIn('id', $users->pluck('id'))->delete();
        });
    }
}
