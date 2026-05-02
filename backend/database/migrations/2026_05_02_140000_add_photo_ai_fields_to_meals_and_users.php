<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-photo-ai-calorie-polish Phase 2 — schema additions.
 *
 *   meals.recognized_via  enum('photo','text','manual')  — analytics on input source
 *   meals.dodo_comment    string(120) nullable           — 朵朵 25 字 NPC 點評（ai-service §schema）
 *   users.photo_ai_used_today  smallint default 0        — daily quota counter (Free=3/day)
 *   users.photo_ai_reset_at    date nullable             — YYYY-MM-DD of the day the counter belongs to
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->string('recognized_via', 16)
                ->default('manual')
                ->after('food_name')
                ->comment('photo / text / manual — analytics on how this meal was logged');
            $table->string('dodo_comment', 120)
                ->nullable()
                ->after('coach_response')
                ->comment('朵朵 NPC 25 字點評 from ai-service vision recognize');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('photo_ai_used_today')
                ->default(0)
                ->after('island_visits_reset_at');
            $table->date('photo_ai_reset_at')
                ->nullable()
                ->after('photo_ai_used_today')
                ->comment('YYYY-MM-DD of the day this counter belongs to');
        });
    }

    public function down(): void
    {
        Schema::table('meals', function (Blueprint $table) {
            $table->dropColumn(['recognized_via', 'dodo_comment']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['photo_ai_used_today', 'photo_ai_reset_at']);
        });
    }
};
