<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 朵朵端 user mirror — Phase C 已擴充至包含「朵朵業務狀態」。
 *
 * 嚴格分層（ADR-007 §2.3）：
 *
 *   ✅ 允許 — 朵朵業務必要欄位（gamification / health / progression / journey）
 *      高頻寫入、朵朵專屬 domain、影響核心遊戲體驗
 *
 *   ❌ 禁止 — 身份 PII（email / phone / password / address / oauth tokens /
 *      real_name / google_id / line_id / apple_id / birthday）
 *      由 Pandora Core 持有，朵朵需要時即時呼叫 platform API（10s cache）
 *
 * 命名注意：`birth_date` 允許（BMR 計算必需），`birthday` 禁止（身份 PII）。
 *
 * @see ADR-007 §2.3
 * @see database/migrations/..._extend_dodo_users_with_business_columns.php
 *
 * @property string $pandora_user_uuid
 * @property ?string $display_name
 * @property ?string $avatar_url
 * @property ?string $subscription_tier
 * @property ?Carbon $last_synced_at
 * @property ?string $avatar_color
 * @property ?string $avatar_species
 * @property ?string $avatar_animal
 * @property int $daily_pet_count
 * @property ?Carbon $last_pet_date
 * @property ?Carbon $last_gift_date
 * @property ?array $outfits_owned
 * @property ?string $equipped_outfit
 * @property int $friendship
 * @property int $streak_shields
 * @property ?Carbon $shield_last_refill
 * @property ?float $height_cm
 * @property ?float $current_weight_kg
 * @property ?float $target_weight_kg
 * @property ?float $start_weight_kg
 * @property ?Carbon $birth_date
 * @property ?string $gender
 * @property ?string $activity_level
 * @property ?array $allergies
 * @property ?array $dislike_foods
 * @property ?array $favorite_foods
 * @property ?string $dietary_type
 * @property ?int $daily_calorie_target
 * @property ?int $daily_protein_target_g
 * @property ?int $daily_water_goal_ml
 * @property int $level
 * @property int $xp
 * @property int $current_streak
 * @property int $longest_streak
 * @property int $total_days
 * @property ?Carbon $last_active_date
 * @property ?Carbon $subscription_expires_at
 * @property ?Carbon $subscription_expires_at_iso
 * @property ?string $subscription_type
 * @property ?string $membership_tier
 * @property ?Carbon $tier_verified_at
 * @property ?string $fp_ref_code
 * @property ?Carbon $trial_started_at
 * @property ?Carbon $trial_expires_at
 * @property int $island_visits_used
 * @property ?Carbon $island_visits_reset_at
 * @property int $journey_cycle
 * @property int $journey_day
 * @property ?Carbon $journey_last_advance_date
 * @property ?Carbon $journey_started_at
 * @property ?Carbon $onboarded_at
 * @property ?Carbon $disclaimer_ack_at
 * @property ?string $referral_code
 * @property bool $push_enabled
 * @property ?Carbon $deletion_requested_at
 * @property ?Carbon $hard_delete_after
 */
class DodoUser extends Model
{
    protected $table = 'dodo_users';

    protected $primaryKey = 'pandora_user_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        // identity mirror（platform → dodo sync 4 欄位）
        'pandora_user_uuid',
        'display_name',
        'avatar_url',
        'subscription_tier',
        'last_synced_at',

        // gamification
        'avatar_color', 'avatar_species', 'avatar_animal',
        'daily_pet_count', 'last_pet_date', 'last_gift_date',
        'outfits_owned', 'equipped_outfit',
        'friendship', 'streak_shields', 'shield_last_refill',

        // health tracking
        'height_cm', 'current_weight_kg', 'target_weight_kg', 'start_weight_kg',
        'birth_date', 'gender', 'activity_level',
        'allergies', 'dislike_foods', 'favorite_foods', 'dietary_type',
        'daily_calorie_target', 'daily_protein_target_g', 'daily_water_goal_ml',

        // progression
        'level', 'xp', 'current_streak', 'longest_streak', 'total_days', 'last_active_date',

        // subscription / tier mirror
        'subscription_expires_at', 'subscription_expires_at_iso', 'subscription_type',
        'membership_tier', 'tier_verified_at', 'fp_ref_code',
        'trial_started_at', 'trial_expires_at',

        // journey
        'island_visits_used', 'island_visits_reset_at',
        'journey_cycle', 'journey_day', 'journey_last_advance_date', 'journey_started_at',

        // app-internal state
        'onboarded_at', 'disclaimer_ack_at', 'referral_code', 'push_enabled',

        // account deletion
        'deletion_requested_at', 'hard_delete_after',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',

        // gamification
        'last_pet_date' => 'date',
        'last_gift_date' => 'date',
        'outfits_owned' => 'array',
        'shield_last_refill' => 'datetime',

        // health
        'height_cm' => 'float',
        'current_weight_kg' => 'float',
        'target_weight_kg' => 'float',
        'start_weight_kg' => 'float',
        'birth_date' => 'date',
        'allergies' => 'array',
        'dislike_foods' => 'array',
        'favorite_foods' => 'array',

        // progression
        'last_active_date' => 'date',

        // subscription
        'subscription_expires_at' => 'datetime',
        'subscription_expires_at_iso' => 'datetime',
        'tier_verified_at' => 'datetime',
        'trial_started_at' => 'datetime',
        'trial_expires_at' => 'datetime',

        // journey
        'island_visits_reset_at' => 'date',
        'journey_last_advance_date' => 'date',
        'journey_started_at' => 'datetime',

        // app state
        'onboarded_at' => 'datetime',
        'disclaimer_ack_at' => 'datetime',
        'push_enabled' => 'boolean',

        // deletion
        'deletion_requested_at' => 'datetime',
        'hard_delete_after' => 'datetime',
    ];
}
