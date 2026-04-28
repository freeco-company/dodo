<?php

namespace Tests\Feature\Identity;

use App\Models\DodoUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase C — DodoUser 擴充欄位的 fillable / casts / 寫入往返驗證。
 */
class DodoUserBusinessColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dodo_users_table_has_extended_business_columns(): void
    {
        $expected = [
            // gamification
            'avatar_color', 'avatar_species', 'avatar_animal',
            'daily_pet_count', 'last_pet_date', 'last_gift_date',
            'outfits_owned', 'equipped_outfit',
            'friendship', 'streak_shields', 'shield_last_refill',
            // health (notice: birth_date allowed, NOT birthday)
            'height_cm', 'current_weight_kg', 'target_weight_kg', 'start_weight_kg',
            'birth_date', 'gender', 'activity_level',
            'allergies', 'dislike_foods', 'favorite_foods', 'dietary_type',
            'daily_calorie_target', 'daily_protein_target_g', 'daily_water_goal_ml',
            // progression
            'level', 'xp', 'current_streak', 'longest_streak', 'total_days', 'last_active_date',
            // subscription / tier
            'subscription_expires_at', 'subscription_expires_at_iso', 'subscription_type',
            'membership_tier', 'tier_verified_at', 'fp_ref_code',
            'trial_started_at', 'trial_expires_at',
            // journey
            'island_visits_used', 'island_visits_reset_at',
            'journey_cycle', 'journey_day', 'journey_last_advance_date', 'journey_started_at',
            // app state
            'onboarded_at', 'disclaimer_ack_at', 'referral_code', 'push_enabled',
            // deletion
            'deletion_requested_at', 'hard_delete_after',
        ];

        foreach ($expected as $col) {
            $this->assertTrue(
                Schema::hasColumn('dodo_users', $col),
                "dodo_users missing business column `{$col}`",
            );
        }
    }

    public function test_business_columns_are_fillable_and_round_trip(): void
    {
        $uuid = (string) Str::uuid();

        $user = DodoUser::create([
            'pandora_user_uuid' => $uuid,
            'display_name' => 'Tester',
            'avatar_url' => null,
            'subscription_tier' => 'premium',
            'avatar_species' => 'dodo',
            'outfits_owned' => ['hat', 'scarf'],
            'allergies' => ['peanut'],
            'height_cm' => 165.5,
            'birth_date' => '1995-03-14',
            'level' => 7,
            'xp' => 1234,
            'current_streak' => 5,
            'push_enabled' => false,
            'journey_started_at' => now(),
        ]);

        $fresh = DodoUser::find($uuid);
        $this->assertNotNull($fresh);

        // array casts
        $this->assertSame(['hat', 'scarf'], $fresh->outfits_owned);
        $this->assertSame(['peanut'], $fresh->allergies);

        // float / int / bool casts
        $this->assertEqualsWithDelta(165.5, $fresh->height_cm, 0.0001);
        $this->assertSame(7, $fresh->level);
        $this->assertSame(1234, $fresh->xp);
        $this->assertFalse($fresh->push_enabled);

        // date cast (birth_date is health, NOT identity birthday)
        $this->assertSame('1995-03-14', $fresh->birth_date->format('Y-m-d'));
    }

    public function test_birthday_is_NOT_a_column_only_birth_date_is(): void
    {
        // 命名邊界 — birthday 是身份 PII（FORBIDDEN_COLUMNS），birth_date 是健康欄位
        $this->assertFalse(Schema::hasColumn('dodo_users', 'birthday'));
        $this->assertTrue(Schema::hasColumn('dodo_users', 'birth_date'));
    }
}
