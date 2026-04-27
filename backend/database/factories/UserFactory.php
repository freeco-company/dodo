<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        $start = fake()->randomFloat(1, 60, 90);
        $current = $start - fake()->randomFloat(1, 0, 5);

        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),

            'avatar_color' => fake()->randomElement(['peach', 'mint', 'lavender', 'cream', 'rose']),
            'avatar_species' => fake()->randomElement(['balance', 'protein', 'fiber', 'hydro', 'energy']),
            'avatar_animal' => fake()->randomElement(['cat', 'rabbit', 'bear', 'hamster', 'fox']),

            'height_cm' => fake()->randomFloat(1, 150, 175),
            'start_weight_kg' => $start,
            'current_weight_kg' => $current,
            'target_weight_kg' => $current - fake()->randomFloat(1, 3, 10),
            'birth_date' => fake()->dateTimeBetween('-45 years', '-22 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['female', 'male']),
            'activity_level' => fake()->randomElement(['sedentary', 'light', 'moderate', 'active']),
            'dietary_type' => 'normal',
            'allergies' => [],
            'dislike_foods' => [],
            'favorite_foods' => [],
            'outfits_owned' => ['none'],

            'level' => fake()->numberBetween(1, 30),
            'xp' => fake()->numberBetween(0, 5000),
            'current_streak' => fake()->numberBetween(0, 30),
            'longest_streak' => fake()->numberBetween(0, 60),
            'total_days' => fake()->numberBetween(1, 90),

            'membership_tier' => 'public',
            'subscription_type' => 'none',
            'daily_water_goal_ml' => 3000,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function fpLifetime(): static
    {
        return $this->state(fn () => [
            'membership_tier' => 'fp_lifetime',
            'fp_ref_code' => 'FP' . fake()->numerify('######'),
            'tier_verified_at' => now(),
        ]);
    }

    public function appMonthly(): static
    {
        return $this->state(fn () => [
            'subscription_type' => 'app_monthly',
            'subscription_expires_at_iso' => now()->addMonth(),
        ]);
    }
}
