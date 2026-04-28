<?php

namespace Database\Factories;

use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyLog>
 */
class DailyLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => fake()->unique()->dateTimeBetween('-30 days', 'now')->format('Y-m-d'),
            'total_score' => fake()->numberBetween(40, 100),
            'calorie_score' => fake()->numberBetween(0, 100),
            'protein_score' => fake()->numberBetween(0, 100),
            'consistency_score' => fake()->numberBetween(0, 100),
            'exercise_score' => fake()->numberBetween(0, 100),
            'hydration_score' => fake()->numberBetween(0, 100),
            'total_calories' => fake()->numberBetween(1200, 2400),
            'total_protein_g' => fake()->randomFloat(1, 40, 120),
            'total_carbs_g' => fake()->randomFloat(1, 100, 280),
            'total_fat_g' => fake()->randomFloat(1, 30, 90),
            'total_fiber_g' => fake()->randomFloat(1, 5, 30),
            'water_ml' => fake()->numberBetween(1000, 3500),
            'exercise_minutes' => fake()->numberBetween(0, 90),
            'meals_logged' => fake()->numberBetween(1, 5),
            'weight_kg' => fake()->randomFloat(1, 55, 80),
            'xp_earned' => fake()->numberBetween(0, 200),
        ];
    }
}
