<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-weekly-ai-report Phase 1 — extend existing weekly_reports table with
 * a shared_count field so we can track「分享圖卡」usage for funnel analytics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->unsignedInteger('shared_count')
                ->default(0)
                ->after('letter_content');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_reports', function (Blueprint $table) {
            $table->dropColumn('shared_count');
        });
    }
};
