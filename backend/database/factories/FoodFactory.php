<?php

namespace Database\Factories;

use App\Models\Food;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Food>
 */
class FoodFactory extends Factory
{
    protected $model = Food::class;

    public function definition(): array
    {
        return [
            'name_zh' => fake()->randomElement(['雞胸肉', '糙米飯', '蒸地瓜', '花椰菜', '蘋果', '優格', '豆漿', '燕麥']),
            'name_en' => fake()->word(),
            'category' => fake()->randomElement(['protein', 'carb', 'veggie', 'fruit', 'dairy', 'beverage']),
            'element' => fake()->randomElement(['protein', 'carb', 'veggie', 'fat', 'sweet', 'drink', 'neutral']),
            'serving_description' => '1 份',
            'serving_weight_g' => fake()->randomFloat(0, 80, 300),
            'calories' => fake()->numberBetween(50, 400),
            'protein_g' => fake()->randomFloat(1, 1, 30),
            'carbs_g' => fake()->randomFloat(1, 1, 60),
            'fat_g' => fake()->randomFloat(1, 0, 20),
            'fiber_g' => fake()->randomFloat(1, 0, 10),
            'sodium_mg' => fake()->randomFloat(0, 0, 800),
            'sugar_g' => fake()->randomFloat(1, 0, 20),
            'variants' => [],
            'aliases' => [],
            'verified' => true,
        ];
    }
}
