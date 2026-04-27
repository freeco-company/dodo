<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => fake()->randomElement(['user', 'assistant']),
            'content' => fake()->sentence(),
            'scenario' => fake()->randomElement(['daily_check_in', 'meal_feedback', 'free_chat', null]),
            'model_used' => 'claude-haiku-4-5',
            'tokens_used' => fake()->numberBetween(20, 800),
        ];
    }
}
