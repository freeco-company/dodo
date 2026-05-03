<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SPEC-photo-ai-correction-v2 PR #1 — per-dish 修正體驗 schema.
 *
 * meal_dishes
 *   一個 meal 拆成多個 dish (1-N 道菜)，per-dish 含 confidence + candidates
 *   + portion_multiplier 讓 frontend 滑桿不破壞原始估值。
 *
 * food_corrections
 *   audit log + 學習回饋來源；用於 ai-service 二次推論時帶 user_calibration hint。
 *
 * Existing meals.{calories, protein_g, carbs_g, fat_g} 維持為「dishes 的 sum」
 * 由 service layer 在 dish 變動時 sync。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_dishes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meal_id')->constrained()->cascadeOnDelete();
            $t->string('food_name');
            $t->string('food_key', 64)->nullable()->index();
            $t->decimal('portion_multiplier', 4, 2)->default(1.00);
            $t->string('portion_unit', 16)->nullable();
            $t->unsignedInteger('kcal')->default(0);
            $t->decimal('carb_g', 6, 2)->default(0);
            $t->decimal('protein_g', 6, 2)->default(0);
            $t->decimal('fat_g', 6, 2)->default(0);
            $t->decimal('confidence', 3, 2)->nullable();
            $t->string('source', 16)->default('ai_initial');
            $t->json('candidates_json')->nullable();
            $t->unsignedSmallInteger('display_order')->default(0);
            $t->timestamps();

            $t->index(['meal_id', 'display_order']);
        });

        Schema::create('food_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('meal_dish_id')->nullable()->constrained()->nullOnDelete();
            $t->string('correction_type', 24);
            $t->string('original_food_key', 64)->nullable();
            $t->string('corrected_food_key', 64)->nullable();
            $t->decimal('original_portion', 4, 2)->nullable();
            $t->decimal('corrected_portion', 4, 2)->nullable();
            $t->decimal('original_confidence', 3, 2)->nullable();
            $t->json('context_json')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'created_at']);
            $t->index(['user_id', 'corrected_food_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_corrections');
        Schema::dropIfExists('meal_dishes');
    }
};
