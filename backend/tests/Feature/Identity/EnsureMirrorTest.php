<?php

namespace Tests\Feature\Identity;

use App\Models\DodoUser;
use App\Models\User;
use App\Services\Identity\DodoUserSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase D Wave 1 — DodoUserSyncService::ensureMirror() 行為。
 *
 * @see ADR-007 §2.3
 */
class EnsureMirrorTest extends TestCase
{
    use RefreshDatabase;

    private DodoUserSyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sync = $this->app->make(DodoUserSyncService::class);
    }

    public function test_ensure_mirror_creates_dodo_user_for_new_legacy_user(): void
    {
        // observer 會自動 ensureMirror，這裡刻意 detach observer 來精準驗證 service
        User::flushEventListeners();

        $user = User::factory()->create(['pandora_user_uuid' => null]);
        $this->assertNull($user->pandora_user_uuid);

        $mirror = $this->sync->ensureMirror($user);

        $this->assertNotNull($user->fresh()->pandora_user_uuid);
        $this->assertSame($user->fresh()->pandora_user_uuid, $mirror->pandora_user_uuid);
        $this->assertSame($user->level, $mirror->level);
        $this->assertSame($user->xp, $mirror->xp);
    }

    public function test_ensure_mirror_does_not_resign_uuid_when_user_already_has_one(): void
    {
        User::flushEventListeners();

        $existingUuid = '01900000-0000-7000-8000-000000000001';
        $user = User::factory()->create(['pandora_user_uuid' => $existingUuid]);

        $mirror = $this->sync->ensureMirror($user);

        $this->assertSame($existingUuid, $user->fresh()->pandora_user_uuid);
        $this->assertSame($existingUuid, $mirror->pandora_user_uuid);
    }

    public function test_ensure_mirror_is_idempotent(): void
    {
        User::flushEventListeners();

        $user = User::factory()->create(['pandora_user_uuid' => null]);

        $first = $this->sync->ensureMirror($user);
        $firstUuid = $first->pandora_user_uuid;
        $firstSyncedAt = $first->last_synced_at;

        // 第二次呼叫不應重簽 uuid，也不應創出第二個 mirror
        $second = $this->sync->ensureMirror($user->fresh());

        $this->assertSame($firstUuid, $second->pandora_user_uuid);
        $this->assertSame(1, DodoUser::query()->count());
        // last_synced_at 會更新（同一筆 row update），不會建第二筆
        $this->assertGreaterThanOrEqual(
            $firstSyncedAt->getTimestamp(),
            $second->last_synced_at->getTimestamp()
        );
    }

    public function test_ensure_mirror_propagates_business_state(): void
    {
        User::flushEventListeners();

        $user = User::factory()->create([
            'pandora_user_uuid' => null,
            'level' => 42,
            'xp' => 9999,
            'current_streak' => 17,
            'allergies' => ['peanut', 'shellfish'],
            'height_cm' => 168.5,
        ]);

        $mirror = $this->sync->ensureMirror($user);

        $this->assertSame(42, $mirror->level);
        $this->assertSame(9999, $mirror->xp);
        $this->assertSame(17, $mirror->current_streak);
        $this->assertSame(['peanut', 'shellfish'], $mirror->allergies);
        $this->assertEqualsWithDelta(168.5, $mirror->height_cm, 0.01);
    }

    public function test_user_dodo_user_relation_resolves(): void
    {
        User::flushEventListeners();

        $user = User::factory()->create(['pandora_user_uuid' => null]);
        $this->sync->ensureMirror($user);

        $user->refresh();
        $this->assertNotNull($user->dodoUser);
        $this->assertSame($user->pandora_user_uuid, $user->dodoUser->pandora_user_uuid);
    }
}
