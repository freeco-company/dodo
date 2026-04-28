<?php

namespace Tests\Feature\Identity;

use App\Models\DodoUser;
use App\Models\User;
use App\Services\Identity\DodoUserSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase C — DodoUserSyncService 雙向 mirror 行為。
 *
 * 兩條路線：
 *   1. platform → DodoUser（identity 4 欄位）
 *   2. legacy User ↔ DodoUser（業務狀態雙向 mirror，Phase F drop user_id 後刪除）
 */
class DodoUserSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private DodoUserSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sync = $this->app->make(DodoUserSyncService::class);
    }

    public function test_sync_from_platform_creates_mirror_with_identity_columns_only(): void
    {
        $uuid = (string) Str::uuid();

        $mirror = $this->sync->syncFromPlatform($uuid, [
            'uuid' => $uuid,
            'display_name' => 'Alice',
            'avatar_url' => 'https://cdn/a.png',
            'subscription_tier' => 'premium',
            // 嘗試夾 PII / 業務狀態 — 都應該被忽略
            'email' => 'leak@x.tld',
            'level' => 99,
        ]);

        $this->assertSame('Alice', $mirror->display_name);
        $this->assertSame('premium', $mirror->subscription_tier);
        $this->assertNotNull($mirror->last_synced_at);
        // 業務狀態欄位不會被 platform 寫
        $this->assertSame(1, $mirror->level); // default
    }

    public function test_sync_from_platform_preserves_existing_business_state(): void
    {
        $uuid = (string) Str::uuid();
        DodoUser::create([
            'pandora_user_uuid' => $uuid,
            'level' => 8,
            'xp' => 4500,
        ]);

        $this->sync->syncFromPlatform($uuid, [
            'uuid' => $uuid,
            'display_name' => 'Bob',
            'subscription_tier' => 'premium',
        ]);

        $fresh = DodoUser::find($uuid);
        $this->assertSame('Bob', $fresh->display_name);
        $this->assertSame(8, $fresh->level);
        $this->assertSame(4500, $fresh->xp);
    }

    public function test_business_state_user_to_mirror_copies_gamification_and_health(): void
    {
        $uuid = (string) Str::uuid();
        $legacy = User::factory()->create([
            'level' => 15,
            'xp' => 7777,
            'current_streak' => 11,
            'allergies' => ['shrimp'],
            'height_cm' => 170.0,
        ]);
        $mirror = DodoUser::create(['pandora_user_uuid' => $uuid]);

        $this->sync->syncBusinessState($legacy, $mirror, 'user-to-mirror');

        $fresh = DodoUser::find($uuid);
        $this->assertSame(15, $fresh->level);
        $this->assertSame(7777, $fresh->xp);
        $this->assertSame(11, $fresh->current_streak);
        $this->assertSame(['shrimp'], $fresh->allergies);
        $this->assertEqualsWithDelta(170.0, $fresh->height_cm, 0.0001);
    }

    public function test_business_state_mirror_to_user_pushes_back_to_legacy(): void
    {
        $uuid = (string) Str::uuid();
        $legacy = User::factory()->create(['level' => 1, 'xp' => 0]);
        $mirror = DodoUser::create([
            'pandora_user_uuid' => $uuid,
            'level' => 22,
            'xp' => 12345,
        ]);

        $this->sync->syncBusinessState($legacy, $mirror, 'mirror-to-user');

        $legacy->refresh();
        $this->assertSame(22, $legacy->level);
        $this->assertSame(12345, $legacy->xp);
    }

    public function test_business_state_does_not_leak_pii_to_mirror(): void
    {
        // 萬一未來 syncBusinessState 被誤改，PII 欄位也不可能落地 — schema 本身擋
        $uuid = (string) Str::uuid();
        $legacy = User::factory()->create([
            'email' => 'private@example.com',
            'name' => 'Real Name',
        ]);
        $mirror = DodoUser::create(['pandora_user_uuid' => $uuid]);

        $this->sync->syncBusinessState($legacy, $mirror, 'user-to-mirror');

        // attribute 不存在於 fresh model（schema 沒這欄）
        $fresh = DodoUser::find($uuid);
        $this->assertArrayNotHasKey('email', $fresh->getAttributes());
        $this->assertArrayNotHasKey('name', $fresh->getAttributes());
    }
}
