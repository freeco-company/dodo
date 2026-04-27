<?php

namespace Database\Factories;

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Achievement>
 */
class AchievementFactory extends Factory
{
    public function definition(): array
    {
        $catalog = [
            'first_meal' => '第一餐打卡',
            'streak_7' => '連續打卡 7 天',
            'streak_30' => '連續打卡 30 天',
            'level_10' => '等級 10',
            'first_perfect_score' => '首次滿分日',
        ];
        $key = fake()->randomElement(array_keys($catalog));

        return [
            'user_id' => User::factory(),
            'achievement_key' => $key,
            'achievement_name' => $catalog[$key],
            'unlocked_at' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }
}
