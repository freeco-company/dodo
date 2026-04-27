<?php

namespace Database\Factories;

use App\Models\Meal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meal>
 */
class MealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => fake()->dateTimeBetween('-14 days', 'now')->format('Y-m-d'),
            'meal_type' => fake()->randomElement(['breakfast', 'lunch', 'dinner', 'snack']),
            'food_name' => fake()->randomElement(['雞胸便當', '日式咖哩', '燕麥粥', '滷肉飯', '凱薩沙拉']),
            'food_components' => [],
            'matched_food_ids' => [],
            'serving_weight_g' => fake()->randomFloat(1, 150, 600),
            'calories' => fake()->numberBetween(250, 900),
            'protein_g' => fake()->randomFloat(1, 10, 50),
            'carbs_g' => fake()->randomFloat(1, 30, 120),
            'fat_g' => fake()->randomFloat(1, 5, 40),
            'fiber_g' => fake()->randomFloat(1, 1, 12),
            'sodium_mg' => fake()->randomFloat(0, 200, 1800),
            'sugar_g' => fake()->randomFloat(1, 1, 30),
            'meal_score' => fake()->numberBetween(40, 100),
            'user_corrected' => false,
            'ai_confidence' => fake()->randomFloat(2, 0.6, 0.99),
        ];
    }
}
