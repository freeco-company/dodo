<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'legacy_id', 'pandora_user_uuid', 'line_id', 'apple_id',
    'name', 'email', 'password',
    'avatar_color', 'avatar_species', 'avatar_animal',
    'daily_pet_count', 'last_pet_date', 'last_gift_date', 'outfits_owned', 'equipped_outfit',
    'height_cm', 'current_weight_kg', 'target_weight_kg', 'start_weight_kg',
    'birth_date', 'gender', 'activity_level',
    'allergies', 'dislike_foods', 'favorite_foods', 'dietary_type',
    'level', 'xp', 'current_streak', 'longest_streak', 'total_days', 'last_active_date',
    'subscription_tier', 'subscription_expires_at',
    'daily_calorie_target', 'daily_protein_target_g',
    'friendship', 'streak_shields', 'shield_last_refill',
    'membership_tier', 'subscription_type', 'subscription_expires_at_iso',
    'fp_ref_code', 'tier_verified_at',
    'island_visits_used', 'island_visits_reset_at',
    'journey_cycle', 'journey_day', 'journey_last_advance_date', 'journey_started_at',
    'api_token', 'daily_water_goal_ml', 'onboarded_at', 'disclaimer_ack_at',
    'referral_code', 'deletion_requested_at', 'hard_delete_after', 'push_enabled',
    'trial_started_at', 'trial_expires_at',
])]
#[Hidden(['password', 'remember_token', 'api_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'admin') {
            return false;
        }

        return $this->membership_tier === 'fp_lifetime'
            || str_ends_with((string) $this->email, '@dodo.local')
            || str_ends_with((string) $this->email, '@packageplus-tw.com');
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',

            'outfits_owned' => 'array',
            'allergies' => 'array',
            'dislike_foods' => 'array',
            'favorite_foods' => 'array',

            'last_pet_date' => 'date',
            'last_gift_date' => 'date',
            'birth_date' => 'date',
            'last_active_date' => 'date',
            'island_visits_reset_at' => 'date',
            'journey_last_advance_date' => 'date',

            'subscription_expires_at' => 'datetime',
            'subscription_expires_at_iso' => 'datetime',
            'tier_verified_at' => 'datetime',
            'shield_last_refill' => 'datetime',
            'journey_started_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'disclaimer_ack_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'hard_delete_after' => 'datetime',
            'trial_started_at' => 'datetime',
            'trial_expires_at' => 'datetime',
            'push_enabled' => 'boolean',

            'height_cm' => 'float',
            'current_weight_kg' => 'float',
            'target_weight_kg' => 'float',
            'start_weight_kg' => 'float',
        ];
    }

    /**
     * 1:1 link 到 Pandora Core identity mirror（Phase D Wave 1 起用 pandora_user_uuid 配對）。
     *
     * 為什麼用 hasOne 而非 belongsTo：legacy User 仍是 Phase A authentication SoT，
     * DodoUser 是這個 user 在朵朵的 mirror（從屬關係）。Phase F drop user_id 後反過來。
     */
    public function dodoUser(): HasOne
    {
        return $this->hasOne(DodoUser::class, 'pandora_user_uuid', 'pandora_user_uuid');
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
    }

    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function summary(): HasOne
    {
        return $this->hasOne(UserSummary::class);
    }

    public function weeklyReports(): HasMany
    {
        return $this->hasMany(WeeklyReport::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    public function foodDiscoveries(): HasMany
    {
        return $this->hasMany(FoodDiscovery::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    public function cardPlays(): HasMany
    {
        return $this->hasMany(CardPlay::class);
    }

    public function cardEventOffers(): HasMany
    {
        return $this->hasMany(CardEventOffer::class);
    }

    public function dailyQuests(): HasMany
    {
        return $this->hasMany(DailyQuest::class);
    }

    public function storeVisits(): HasMany
    {
        return $this->hasMany(StoreVisit::class);
    }

    public function journeyAdvances(): HasMany
    {
        return $this->hasMany(JourneyAdvance::class);
    }
}
