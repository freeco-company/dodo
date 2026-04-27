<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Translated from ai-game/src/services/referrals.ts.
 *
 * Each user gets an 8-char alphanumeric referral_code (no I/O/0/1 to dodge typos).
 * Redemption rules:
 *  - Each user can only be referred once (UNIQUE referee_id)
 *  - Self-referral blocked
 *  - Reward: both sides get +7 days trial extension
 */
class ReferralService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const REWARD_DAYS = 7;

    public function __construct(private readonly TrialService $trial) {}

    public function ensureCode(User $user): string
    {
        if ($user->referral_code) return $user->referral_code;

        // Generate + retry on rare UNIQUE collision.
        for ($i = 0; $i < 10; $i++) {
            $code = $this->randomCode();
            try {
                $user->referral_code = $code;
                $user->save();
                return $code;
            } catch (\Throwable $e) {
                // collision — try again
                $user->referral_code = null;
            }
        }
        abort(500, 'FAILED_TO_GENERATE_REFERRAL_CODE');
    }

    public function findUserByCode(string $code): ?User
    {
        $cleaned = strtoupper(trim($code));
        if ($cleaned === '') return null;
        return User::where('referral_code', $cleaned)->first();
    }

    /**
     * Apply a referral. Returns null on invalid / self-referral / already redeemed.
     *
     * @return array{referrer_id:int, referee_id:int, reward_kind:string, trial_expires_at:string}|null
     */
    public function apply(User $referee, string $code): ?array
    {
        $cleaned = strtoupper(trim($code));
        if (strlen($cleaned) < 4) return null;

        $referrer = $this->findUserByCode($cleaned);
        if (! $referrer) return null;
        if ($referrer->id === $referee->id) return null;

        $existing = DB::table('referrals')->where('referee_id', $referee->id)->exists();
        if ($existing) return null;

        $expiresIso = DB::transaction(function () use ($referrer, $referee, $cleaned) {
            DB::table('referrals')->insert([
                'referrer_id' => $referrer->id,
                'referee_id' => $referee->id,
                'code' => $cleaned,
                'reward_kind' => 'trial_extension_7d',
                'created_at' => now(),
            ]);
            $this->trial->extend($referrer, self::REWARD_DAYS);
            $refereeExpires = $this->trial->extend($referee, self::REWARD_DAYS);
            return $refereeExpires->toIso8601String();
        });

        return [
            'referrer_id' => $referrer->id,
            'referee_id' => $referee->id,
            'reward_kind' => 'trial_extension_7d',
            'trial_expires_at' => $expiresIso,
        ];
    }

    /** @return array{code:string, invited_count:int, reward_days_earned:int, invited:array} */
    public function stats(User $user): array
    {
        $code = $this->ensureCode($user);
        $invited = DB::table('referrals')
            ->where('referrer_id', $user->id)
            ->orderByDesc('created_at')
            ->get(['referee_id as id', 'created_at']);
        return [
            'code' => $code,
            'invited_count' => $invited->count(),
            'reward_days_earned' => $invited->count() * self::REWARD_DAYS,
            'invited' => $invited->all(),
        ];
    }

    private function randomCode(int $len = 8): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $len; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }
}
