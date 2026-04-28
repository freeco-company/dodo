<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase C (ADR-007 §2.3) — 把 legacy `users` 表的「朵朵業務狀態」搬進 `dodo_users`。
 *
 * ADR-007 §2.3 原文允許 dodo_users mirror：
 *
 *   「uuid + display_name + avatar + 該 App 必要欄位（如訂閱層級）」
 *
 * 「該 App 必要欄位」literally 包含朵朵的 gamification / health-tracking /
 * progression 等業務狀態。所以以下欄位都是 ADR 准許的（PR #2 那條過嚴的
 * whitelist test 在 Phase C 同步調整為 PII deny-list）。
 *
 * 嚴格邊界 — 以下絕對 NOT 進 dodo_users（永遠由 Pandora Core 持有）：
 *
 *   ❌ email / email_canonical / phone / phone_canonical
 *   ❌ password / password_hash / remember_token / api_token
 *   ❌ google_id / line_id / apple_id / oauth_token / refresh_token
 *   ❌ real_name / 中文姓名 / address / id_number / birthday（身份用詞）
 *
 * 為什麼用 birth_date 不用 birthday：
 *   - birth_date：朵朵 BMR / BMI 計算「必要」的健康追蹤欄位
 *   - birthday：身份系統用詞（生日問候、身份核對），屬 PII，留在 platform
 *   - 命名上明顯區分，避免後續加欄位時誤踩線
 *
 * 為什麼這些欄位「不只是 PII 邊界」、還必須留在朵朵：
 *   - 高頻寫入（每餐記錄、每次互動）— 走 platform 來回延遲不可接受
 *   - 朵朵專屬語意（friendship / journey_cycle / outfits_owned）—
 *     platform 無此 domain knowledge
 *   - 影響核心遊戲體驗（streak_shields / level / xp）
 *
 * @see ADR-007 §2.3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dodo_users', function (Blueprint $table) {
            // ── Avatar / pet 互動（gamification） ─────────────────────────
            $table->string('avatar_color', 32)->nullable()->after('avatar_url');
            $table->string('avatar_species', 32)->nullable()->after('avatar_color');
            $table->string('avatar_animal', 32)->nullable()->after('avatar_species');
            $table->unsignedSmallInteger('daily_pet_count')->default(0)->after('avatar_animal');
            $table->date('last_pet_date')->nullable()->after('daily_pet_count');
            $table->date('last_gift_date')->nullable()->after('last_pet_date');
            $table->json('outfits_owned')->nullable()->after('last_gift_date');
            $table->string('equipped_outfit', 64)->nullable()->after('outfits_owned');
            $table->unsignedInteger('friendship')->default(0)->after('equipped_outfit');
            $table->unsignedSmallInteger('streak_shields')->default(0)->after('friendship');
            $table->timestamp('shield_last_refill')->nullable()->after('streak_shields');

            // ── Health tracking（BMR / 卡路里計算的基礎） ─────────────────
            $table->float('height_cm')->nullable()->after('shield_last_refill');
            $table->float('current_weight_kg')->nullable()->after('height_cm');
            $table->float('target_weight_kg')->nullable()->after('current_weight_kg');
            $table->float('start_weight_kg')->nullable()->after('target_weight_kg');
            // birth_date：BMR 計算必需。命名刻意避開 birthday 以區隔身份 PII。
            $table->date('birth_date')->nullable()->after('start_weight_kg');
            $table->string('gender', 16)->nullable()->after('birth_date');
            $table->string('activity_level', 32)->nullable()->after('gender');
            $table->json('allergies')->nullable()->after('activity_level');
            $table->json('dislike_foods')->nullable()->after('allergies');
            $table->json('favorite_foods')->nullable()->after('dislike_foods');
            $table->string('dietary_type', 32)->nullable()->after('favorite_foods');
            $table->unsignedInteger('daily_calorie_target')->nullable()->after('dietary_type');
            $table->unsignedSmallInteger('daily_protein_target_g')->nullable()->after('daily_calorie_target');
            $table->unsignedInteger('daily_water_goal_ml')->nullable()->after('daily_protein_target_g');

            // ── Progression（level / streak / xp） ───────────────────────
            $table->unsignedSmallInteger('level')->default(1)->after('daily_water_goal_ml');
            $table->unsignedInteger('xp')->default(0)->after('level');
            $table->unsignedSmallInteger('current_streak')->default(0)->after('xp');
            $table->unsignedSmallInteger('longest_streak')->default(0)->after('current_streak');
            $table->unsignedInteger('total_days')->default(0)->after('longest_streak');
            $table->date('last_active_date')->nullable()->after('total_days');

            // ── Subscription / tier mirror（platform 同步來，朵朵讀） ─────
            // subscription_tier 已在原 migration（保留位置）
            $table->timestamp('subscription_expires_at')->nullable()->after('subscription_tier');
            $table->timestamp('subscription_expires_at_iso')->nullable()->after('subscription_expires_at');
            $table->string('subscription_type', 32)->nullable()->after('subscription_expires_at_iso');
            $table->string('membership_tier', 32)->nullable()->after('subscription_type');
            $table->timestamp('tier_verified_at')->nullable()->after('membership_tier');
            $table->string('fp_ref_code', 32)->nullable()->after('tier_verified_at');
            $table->timestamp('trial_started_at')->nullable()->after('fp_ref_code');
            $table->timestamp('trial_expires_at')->nullable()->after('trial_started_at');

            // ── Journey（朵朵獨有遊戲節奏） ───────────────────────────────
            $table->unsignedSmallInteger('island_visits_used')->default(0)->after('trial_expires_at');
            $table->date('island_visits_reset_at')->nullable()->after('island_visits_used');
            $table->unsignedSmallInteger('journey_cycle')->default(0)->after('island_visits_reset_at');
            $table->unsignedSmallInteger('journey_day')->default(0)->after('journey_cycle');
            $table->date('journey_last_advance_date')->nullable()->after('journey_day');
            $table->timestamp('journey_started_at')->nullable()->after('journey_last_advance_date');

            // ── App-internal state ───────────────────────────────────────
            $table->timestamp('onboarded_at')->nullable()->after('journey_started_at');
            $table->timestamp('disclaimer_ack_at')->nullable()->after('onboarded_at');
            $table->string('referral_code', 32)->nullable()->unique()->after('disclaimer_ack_at');
            $table->boolean('push_enabled')->default(true)->after('referral_code');

            // ── Account deletion request tracking ────────────────────────
            $table->timestamp('deletion_requested_at')->nullable()->after('push_enabled');
            $table->timestamp('hard_delete_after')->nullable()->after('deletion_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('dodo_users', function (Blueprint $table) {
            $table->dropUnique(['referral_code']);
            $table->dropColumn([
                'avatar_color', 'avatar_species', 'avatar_animal',
                'daily_pet_count', 'last_pet_date', 'last_gift_date',
                'outfits_owned', 'equipped_outfit',
                'friendship', 'streak_shields', 'shield_last_refill',
                'height_cm', 'current_weight_kg', 'target_weight_kg', 'start_weight_kg',
                'birth_date', 'gender', 'activity_level',
                'allergies', 'dislike_foods', 'favorite_foods', 'dietary_type',
                'daily_calorie_target', 'daily_protein_target_g', 'daily_water_goal_ml',
                'level', 'xp', 'current_streak', 'longest_streak', 'total_days', 'last_active_date',
                'subscription_expires_at', 'subscription_expires_at_iso', 'subscription_type',
                'membership_tier', 'tier_verified_at', 'fp_ref_code',
                'trial_started_at', 'trial_expires_at',
                'island_visits_used', 'island_visits_reset_at',
                'journey_cycle', 'journey_day', 'journey_last_advance_date', 'journey_started_at',
                'onboarded_at', 'disclaimer_ack_at', 'referral_code', 'push_enabled',
                'deletion_requested_at', 'hard_delete_after',
            ]);
        });
    }
};
