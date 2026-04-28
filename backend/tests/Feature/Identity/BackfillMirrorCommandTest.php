<?php

namespace Tests\Feature\Identity;

use App\Models\DailyLog;
use App\Models\DodoUser;
use App\Models\Meal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase D Wave 1 — `php artisan identity:backfill-mirror`。
 */
class BackfillMirrorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_creates_mirror_for_users_without_uuid(): void
    {
        // 直接落地一個沒有 uuid 的 user — 模擬 Phase A 既有資料
        // observer 會幫 user.factory 自動 ensureMirror，所以我們手動 detach
        User::flushEventListeners();
        $u1 = User::factory()->create(['pandora_user_uuid' => null]);
        $u2 = User::factory()->create(['pandora_user_uuid' => null]);

        $this->assertSame(0, DodoUser::query()->count());

        $this->artisan('identity:backfill-mirror')->assertSuccessful();

        $this->assertNotNull($u1->fresh()->pandora_user_uuid);
        $this->assertNotNull($u2->fresh()->pandora_user_uuid);
        $this->assertSame(2, DodoUser::query()->count());
    }

    public function test_backfill_dry_run_writes_nothing(): void
    {
        User::flushEventListeners();
        $u = User::factory()->create(['pandora_user_uuid' => null]);

        $this->artisan('identity:backfill-mirror', ['--dry' => true])->assertSuccessful();

        $this->assertNull($u->fresh()->pandora_user_uuid);
        $this->assertSame(0, DodoUser::query()->count());
    }

    public function test_backfill_is_idempotent(): void
    {
        $u = User::factory()->create();
        $uuid = $u->fresh()->pandora_user_uuid;
        $this->assertNotNull($uuid);

        $this->artisan('identity:backfill-mirror')->assertSuccessful();
        $this->artisan('identity:backfill-mirror')->assertSuccessful();

        // 不重簽 uuid，不重建 mirror
        $this->assertSame($uuid, $u->fresh()->pandora_user_uuid);
        $this->assertSame(1, DodoUser::query()->count());
    }

    public function test_backfill_propagates_uuid_to_reference_rows(): void
    {
        // 模擬 Phase C 之前就存在的 reference rows — 沒有 uuid
        User::flushEventListeners();
        $u = User::factory()->create(['pandora_user_uuid' => null]);

        // 直接透過 query builder 寫，繞過 trait
        DB::table('daily_logs')->insert([
            'user_id' => $u->id,
            'date' => '2026-04-21',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('meals')->insert([
            'user_id' => $u->id,
            'date' => '2026-04-21',
            'meal_type' => 'lunch',
            'food_name' => 'salad',
            'calories' => 300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('identity:backfill-mirror')->assertSuccessful();

        $u->refresh();
        $this->assertNotNull($u->pandora_user_uuid);

        $this->assertSame($u->pandora_user_uuid, DailyLog::query()->first()->pandora_user_uuid);
        $this->assertSame($u->pandora_user_uuid, Meal::query()->first()->pandora_user_uuid);
    }

    public function test_backfill_propagates_uuid_to_referrals_both_columns(): void
    {
        User::flushEventListeners();
        $referrer = User::factory()->create(['pandora_user_uuid' => null]);
        $referee = User::factory()->create(['pandora_user_uuid' => null]);

        DB::table('referrals')->insert([
            'referrer_id' => $referrer->id,
            'referee_id' => $referee->id,
            'code' => 'ABC123',
            'reward_kind' => 'trial_extension_7d',
            'created_at' => now(),
        ]);

        $this->artisan('identity:backfill-mirror')->assertSuccessful();

        $row = DB::table('referrals')->first();
        $this->assertSame($referrer->fresh()->pandora_user_uuid, $row->pandora_referrer_uuid);
        $this->assertSame($referee->fresh()->pandora_user_uuid, $row->pandora_referee_uuid);
    }
}
