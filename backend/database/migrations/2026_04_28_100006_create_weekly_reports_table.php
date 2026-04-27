<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_reports', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->date('week_end');

            $table->decimal('avg_score', 5, 2)->nullable();
            $table->json('daily_scores')->nullable();
            $table->decimal('weight_change', 5, 2)->nullable();
            $table->unsignedInteger('level_before')->nullable();
            $table->unsignedInteger('level_after')->nullable();
            $table->json('top_foods')->nullable();
            $table->longText('letter_content')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_reports');
    }
};
