<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->string('legacy_id')->nullable()->unique()
                ->comment('Doudou Node 版的 TEXT id，遷移期保留以便資料對映');

            $table->string('line_id')->nullable()->unique();
            $table->string('apple_id')->nullable()->unique();

            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable()->comment('Filament admin / web auth');
            $table->rememberToken();

            $table->string('avatar_color')->default('peach');
            $table->string('avatar_species')->default('balance')
                ->comment('balance/protein/fiber/hydro/energy');
            $table->string('avatar_animal')->default('cat')
                ->comment('cat/rabbit/bear/hamster/fox');
            $table->unsignedInteger('daily_pet_count')->default(0);
            $table->date('last_pet_date')->nullable();
            $table->date('last_gift_date')->nullable();
            $table->json('outfits_owned')->nullable();
            $table->string('equipped_outfit')->default('none');

            $table->decimal('height_cm', 5, 2)->nullable();
            $table->decimal('current_weight_kg', 5, 2)->nullable();
            $table->decimal('target_weight_kg', 5, 2)->nullable();
            $table->decimal('start_weight_kg', 5, 2)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender')->nullable();
            $table->string('activity_level')->default('light');
            $table->json('allergies')->nullable();
            $table->json('dislike_foods')->nullable();
            $table->json('favorite_foods')->nullable();
            $table->string('dietary_type')->default('normal');

            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('xp')->default(0);
            $table->unsignedInteger('current_streak')->default(0);
            $table->unsignedInteger('longest_streak')->default(0);
            $table->unsignedInteger('total_days')->default(0);
            $table->date('last_active_date')->nullable();

            $table->string('subscription_tier')->default('free')
                ->comment('legacy / 過渡期保留');
            $table->timestamp('subscription_expires_at')->nullable();

            $table->unsignedInteger('daily_calorie_target')->nullable();
            $table->unsignedInteger('daily_protein_target_g')->nullable();

            $table->unsignedInteger('friendship')->default(0);
            $table->unsignedInteger('streak_shields')->default(1);
            $table->timestamp('shield_last_refill')->nullable();

            $table->string('membership_tier')->default('public')
                ->comment('public | fp_lifetime');
            $table->string('subscription_type')->default('none')
                ->comment('none | app_monthly | app_yearly');
            $table->timestamp('subscription_expires_at_iso')->nullable();
            $table->string('fp_ref_code')->nullable();
            $table->timestamp('tier_verified_at')->nullable();

            $table->unsignedInteger('island_visits_used')->default(0);
            $table->date('island_visits_reset_at')->nullable()
                ->comment('YYYY-MM-01 of the month this counter belongs to');

            $table->unsignedInteger('journey_cycle')->default(1);
            $table->unsignedInteger('journey_day')->default(1);
            $table->date('journey_last_advance_date')->nullable();
            $table->timestamp('journey_started_at')->nullable();

            $table->string('api_token', 80)->nullable()->unique();
            $table->unsignedInteger('daily_water_goal_ml')->default(3000);
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('disclaimer_ack_at')->nullable();

            $table->timestamps();

            $table->index(['membership_tier', 'subscription_type']);
            $table->index('last_active_date');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
