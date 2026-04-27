<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daily_log_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('meal_type')->comment('breakfast / lunch / dinner / snack');

            $table->string('photo_url', 1024)->nullable();
            $table->string('food_name')->nullable();
            $table->json('food_components')->nullable();
            $table->json('matched_food_ids')->nullable()
                ->comment('legacy ids; mapping to food_database.id done in app layer');
            $table->decimal('serving_weight_g', 8, 2)->nullable();

            $table->unsignedInteger('calories')->default(0);
            $table->decimal('protein_g', 6, 2)->default(0);
            $table->decimal('carbs_g', 6, 2)->default(0);
            $table->decimal('fat_g', 6, 2)->default(0);
            $table->decimal('fiber_g', 6, 2)->default(0);
            $table->decimal('sodium_mg', 8, 2)->default(0);
            $table->decimal('sugar_g', 6, 2)->default(0);

            $table->unsignedInteger('meal_score')->nullable();
            $table->text('coach_response')->nullable();
            $table->boolean('user_corrected')->default(false);
            $table->json('correction_data')->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->longText('ai_raw_response')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meals');
    }
};
