<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-fasting-redesign-v2 Phase B — track which stage we last pushed to the
 * user. Stage transitions (4h/8h/12h/16h/20h) should fire ONE push each.
 * Without this column the cron tick has no idea what's already been notified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fasting_sessions', function (Blueprint $table) {
            $table->string('last_pushed_phase', 24)
                ->nullable()
                ->after('completed')
                ->comment('Last stage key we sent a push for; updated by FastingTickPush command');
            $table->dateTimeTz('eating_window_started_at')
                ->nullable()
                ->after('last_pushed_phase')
                ->comment('When user clicked end → start of eating window. NULL when fasting active or never ended.');
        });
    }

    public function down(): void
    {
        Schema::table('fasting_sessions', function (Blueprint $table) {
            $table->dropColumn(['last_pushed_phase', 'eating_window_started_at']);
        });
    }
};
