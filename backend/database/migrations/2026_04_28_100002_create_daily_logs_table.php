<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_logs', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');

            $table->unsignedInteger('total_score')->default(0);
            $table->unsignedInteger('calorie_score')->default(0);
            $table->unsignedInteger('protein_score')->default(0);
            $table->unsignedInteger('consistency_score')->default(0);
            $table->unsignedInteger('exercise_score')->default(0);
            $table->unsignedInteger('hydration_score')->default(0);

            $table->unsignedInteger('total_calories')->default(0);
            $table->decimal('total_protein_g', 8, 2)->default(0);
            $table->decimal('total_carbs_g', 8, 2)->default(0);
            $table->decimal('total_fat_g', 8, 2)->default(0);
            $table->decimal('total_fiber_g', 8, 2)->default(0);
            $table->unsignedInteger('water_ml')->default(0);
            $table->unsignedInteger('exercise_minutes')->default(0);
            $table->unsignedInteger('meals_logged')->default(0);

            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->text('daily_summary')->nullable();
            $table->unsignedInteger('xp_earned')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'date']);
            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_logs');
    }
};
