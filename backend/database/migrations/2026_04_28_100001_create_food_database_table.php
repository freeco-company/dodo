<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_database', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->string('name_zh');
            $table->string('name_en')->nullable();
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->string('element')->default('neutral')
                ->comment('protein/carb/veggie/fat/sweet/drink/neutral');

            $table->string('serving_description')->nullable();
            $table->decimal('serving_weight_g', 8, 2)->nullable();
            $table->unsignedInteger('calories')->nullable();
            $table->decimal('protein_g', 6, 2)->nullable();
            $table->decimal('carbs_g', 6, 2)->nullable();
            $table->decimal('fat_g', 6, 2)->nullable();
            $table->decimal('fiber_g', 6, 2)->default(0);
            $table->decimal('sodium_mg', 8, 2)->default(0);
            $table->decimal('sugar_g', 6, 2)->default(0);

            $table->json('variants')->nullable();
            $table->json('aliases')->nullable();
            $table->string('source')->nullable();
            $table->boolean('verified')->default(false);

            $table->timestamps();

            $table->index('name_zh');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_database');
    }
};
