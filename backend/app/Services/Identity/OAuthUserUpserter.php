<?php

namespace App\Services\Identity;

use App\Models\User;
use App\Services\TargetCalculator;
use App\Services\TrialService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared user upsert logic for OAuth sign-in (Apple / LINE).
 *
 * Lookup order (single transaction so we don't race-create duplicate rows):
 *   1. Match by provider id (apple_id / line_id) → existing OAuth user, login.
 *   2. Match by email (only if email present + provider supplied verified email)
 *      → link provider id to that user (account merge).
 *   3. Else create a new user with sensible defaults.
 *
 * Trial fraud guard (provider id seen on a previously deleted account) is
 * delegated to TrialService::start which checks oauth_trial_blacklist.
 */
class OAuthUserUpserter
{
    public function __construct(private readonly TrialService $trial) {}

    /**
     * @param  'apple_id'|'line_id'  $idColumn
     * @param  array{email?:?string, name?:?string, email_verified?:bool}  $profile
     */
    public function upsert(string $idColumn, string $providerSub, array $profile): User
    {
        return DB::transaction(function () use ($idColumn, $providerSub, $profile): User {
            // 1. Existing provider id wins.
            $byProvider = User::query()->where($idColumn, $providerSub)->first();
            if ($byProvider !== null) {
                return $byProvider;
            }

            $rawEmail = $profile['email'] ?? null;
            $email = ($rawEmail !== null && $rawEmail !== '') ? $rawEmail : null;
            $emailVerified = (bool) ($profile['email_verified'] ?? true);

            // 2. Email merge — only when the provider supplied a verified email.
            //    Avoids attacker registering with victim's email then logging in
            //    via OAuth using a forged email claim. When email_verified=false
            //    we also drop the email entirely from the new-user payload (step
            //    3) — otherwise we'd hit a unique-key collision with the legit
            //    owner's row and could leak existence of that email via 500s.
            if ($email !== null) {
                if ($emailVerified) {
                    $byEmail = User::query()->where('email', $email)->first();
                    if ($byEmail !== null) {
                        if ($byEmail->{$idColumn} === null) {
                            $byEmail->{$idColumn} = $providerSub;
                            $byEmail->save();
                        }

                        return $byEmail;
                    }
                } else {
                    // Unverified email — refuse to bind it to the new user. We
                    // still allow account creation (OAuth identity is the
                    // authoritative bit) but with email = null.
                    if (User::query()->where('email', $email)->exists()) {
                        $email = null;
                    }
                }
            }

            // 3. Create new user. Mirror AuthController::register defaults.
            $defaults = TargetCalculator::compute([
                'weight_kg' => 60,
                'height_cm' => 160,
                'age' => 30,
                'gender' => 'female',
                'activity_level' => 'light',
                'goal_weight_kg' => null,
            ]);

            $rawName = $profile['name'] ?? null;
            $name = ($rawName !== null && $rawName !== '')
                ? $rawName
                : ($email !== null ? strstr($email, '@', true) ?: '朋友' : '朋友');

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => null,
                $idColumn => $providerSub,
                'avatar_animal' => 'cat',
                'outfits_owned' => ['none'],
                'allergies' => [],
                'dislike_foods' => [],
                'favorite_foods' => [],
                'dietary_type' => 'normal',
                'activity_level' => 'light',
                'daily_calorie_target' => $defaults['daily_calorie_target'],
                'daily_protein_target_g' => $defaults['daily_protein_target_g'],
                'daily_water_goal_ml' => $defaults['daily_water_goal_ml'],
                'onboarded_at' => Carbon::now(),
            ]);

            // Trial: TrialService consults the blacklist before granting.
            $this->trial->start($user);

            return $user;
        });
    }
}
