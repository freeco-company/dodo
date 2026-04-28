<?php

namespace Tests\Feature\Api;

use App\Models\DailyLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lock the /api/me/dashboard JSON shape against the live frontend contract.
 *
 * Why PHPUnit class style (not Pest like the rest): this is a *contract*
 * test, not a behavior test. The intent is "this exact key set must always
 * be present" so a future refactor that drops `doudou.mood_phrase` (etc.)
 * fails loudly with a clear assertion. Class-style assertJsonStructure reads
 * very naturally for that pattern, and pinning to PHPUnit means a Pest
 * config tweak can't accidentally weaken it.
 *
 * Source of truth: `frontend/public/app.js` `loadDashboard()` (audited
 * 2026-04-28). The 26 `d.*` references it pulls become the structure below.
 */
class MeDashboardShapeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shape_matches_frontend_contract(): void
    {
        $user = User::factory()->create([
            'daily_calorie_target' => 1800,
            'daily_protein_target_g' => 80,
            'level' => 3,
            'xp' => 120,
            'current_streak' => 5,
            'longest_streak' => 10,
            'friendship' => 42,
            'streak_shields' => 1,
        ]);

        DailyLog::create([
            'user_id' => $user->id,
            'pandora_user_uuid' => $user->pandora_user_uuid,
            'date' => now()->toDateString(),
            'total_score' => 75,
            'meals_logged' => 2,
            'water_ml' => 800,
            'exercise_minutes' => 20,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/me/dashboard')
            ->assertOk();

        // Top-level keys the frontend reads.
        $response->assertJsonStructure([
            'user' => [
                'id', 'name',
                'avatar_color', 'avatar_species', 'avatar_animal',
                'equipped_outfit',
                'current_weight_kg', 'target_weight_kg',
                'daily_calorie_target', 'daily_protein_target_g',
                'subscription_tier',
            ],
            'today' => [
                'date',
                // Legacy keys — kept for backward compat.
                'score', 'meals_logged', 'calories', 'calories_target',
                'protein_g', 'protein_target_g',
                // Frontend-expected keys (loadDashboard d.today.*).
                'total_calories', 'total_protein_g',
                'total_carbs_g', 'total_fat_g', 'total_fiber_g',
                'total_score',
                'water_ml', 'exercise_minutes',
                'remaining_calories',
                'meals',
            ],
            'progress' => [
                'level', 'xp', 'current_streak', 'longest_streak', 'friendship',
            ],
            'doudou' => [
                'level', 'level_name',
                'mood', 'mood_phrase',
                'xp', 'xp_next_level', 'xp_progress',
                'streak', 'streak_shields', 'friendship',
            ],
            'tasks',
            'last7',
            'achievements',
        ]);

        // Spot-check the computed values frontend depends on.
        $response->assertJsonPath('user.daily_calorie_target', 1800);
        $response->assertJsonPath('today.calories_target', 1800);
        $response->assertJsonPath('today.water_ml', 800);
        $response->assertJsonPath('today.exercise_minutes', 20);
        $response->assertJsonPath('today.total_score', 75);
        $response->assertJsonPath('doudou.level', 3);
        $response->assertJsonPath('doudou.streak', 5);
        $response->assertJsonPath('doudou.streak_shields', 1);
        $response->assertJsonPath('doudou.friendship', 42);
        $response->assertJsonPath('doudou.level_name', '幼苗'); // level 3 → 幼苗 bucket

        // tasks is array (may be empty until Phase F)
        $this->assertIsArray($response->json('tasks'));
    }

    public function test_dashboard_xp_progress_clamped_between_zero_and_one(): void
    {
        $user = User::factory()->create([
            'level' => 1,
            'xp' => 25,                                // halfway through level 1→2 (50 XP/level)
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/me/dashboard')
            ->assertOk();

        $progress = $response->json('doudou.xp_progress');
        $this->assertIsNumeric($progress);
        $this->assertGreaterThanOrEqual(0.0, $progress);
        $this->assertLessThanOrEqual(1.0, $progress);

        // xp_next_level must be a positive int (frontend renders "已滿" only
        // when this is falsy → never set null/0 here).
        $this->assertIsInt($response->json('doudou.xp_next_level'));
        $this->assertGreaterThan(0, $response->json('doudou.xp_next_level'));
    }

    public function test_dashboard_handles_user_with_no_daily_log(): void
    {
        // Frontend must not crash when DailyLog row is absent (new user, day 1).
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/me/dashboard')
            ->assertOk()
            ->assertJsonPath('today.water_ml', 0)
            ->assertJsonPath('today.exercise_minutes', 0)
            ->assertJsonPath('today.total_score', 0);

        // 0.0 round-trips through json_encode as integer 0; assert numeric
        // equality rather than strict identity to avoid float/int mismatch.
        $this->assertSame(0, (int) $response->json('today.total_carbs_g'));
        $this->assertSame(0, (int) $response->json('today.total_fat_g'));
        $this->assertSame(0, (int) $response->json('today.total_fiber_g'));
    }
}
