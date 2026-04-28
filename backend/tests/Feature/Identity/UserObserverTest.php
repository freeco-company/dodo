<?php

namespace Tests\Feature\Identity;

use App\Models\DodoUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase D Wave 1 — 確認 UserObserver 在 saved 自動 ensureMirror。
 */
class UserObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_legacy_user_auto_creates_mirror(): void
    {
        $user = User::factory()->create();

        $this->assertNotNull($user->fresh()->pandora_user_uuid, 'observer should fill uuid on save');
        $this->assertNotNull(
            DodoUser::find($user->fresh()->pandora_user_uuid),
            'observer should create DodoUser mirror'
        );
    }

    public function test_updating_user_business_state_propagates_to_mirror(): void
    {
        $user = User::factory()->create(['level' => 1, 'xp' => 0]);

        $user->level = 25;
        $user->xp = 8000;
        $user->save();

        $mirror = DodoUser::find($user->fresh()->pandora_user_uuid);
        $this->assertSame(25, $mirror->level);
        $this->assertSame(8000, $mirror->xp);
    }

    public function test_observer_does_not_recurse_on_save(): void
    {
        // 觀察者內部 ensureMirror 會 user->save()，必須有防遞迴機制
        $user = User::factory()->create();

        // 若 recursion 沒擋住會拋 stack overflow / max nesting
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, DodoUser::query()->count());
    }
}
