<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_discoveries', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_id')->nullable()->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained('food_database')->cascadeOnDelete();

            $table->timestamp('first_seen_at')->useCurrent();
            $table->unsignedInteger('times_eaten')->default(1);
            $table->unsignedInteger('best_score')->nullable();
            $table->boolean('is_shiny')->default(false)
                ->comment('Pokemon-style: unlocked when best_score >= 90');

            $table->unique(['user_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_discoveries');
    }
};
