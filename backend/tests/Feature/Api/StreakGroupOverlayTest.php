<?php

use App\Models\User;
use App\Services\Dodo\Streak\GroupStreakClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 4, 9, 0, 0, 'Asia/Taipei'));
});

afterEach(function () {
    Carbon::setTestNow();
});

it('GET /api/streak/today returns group payload when GroupStreakClient resolves', function () {
    $stub = new class extends GroupStreakClient
    {
        public function fetch(string $pandoraUserUuid): ?array
        {
            return [
                'current_streak' => 12,
                'longest_streak' => 25,
                'today_in_streak' => true,
                'last_login_date' => '2026-05-04',
            ];
        }
    };
    app()->instance(GroupStreakClient::class, $stub);

    $user = User::factory()->create(['pandora_user_uuid' => '11111111-2222-3333-4444-555555555555']);
    Sanctum::actingAs($user);

    $this->getJson('/api/streak/today')
        ->assertOk()
        ->assertJsonPath('group.current_streak', 12)
        ->assertJsonPath('group.longest_streak', 25)
        ->assertJsonPath('group.today_in_streak', true);
});

it('GET /api/streak/today returns group=null when GroupStreakClient throws (fail-soft)', function () {
    $stub = new class extends GroupStreakClient
    {
        public function fetch(string $pandoraUserUuid): ?array
        {
            // Production code shouldn't throw (fetch is itself fail-soft) but if
            // anything bubbles, the controller must still return 200 + group=null.
            throw new \RuntimeException('simulated py-service outage');
        }
    };
    app()->instance(GroupStreakClient::class, $stub);

    $user = User::factory()->create(['pandora_user_uuid' => '11111111-2222-3333-4444-555555555555']);
    Sanctum::actingAs($user);

    $this->getJson('/api/streak/today')
        ->assertOk()
        ->assertJsonPath('group', null);
});

it('GET /api/streak/today returns group=null when user has no pandora_user_uuid', function () {
    // Bind a stub that would explode if called — proves we skip when uuid empty.
    $stub = new class extends GroupStreakClient
    {
        public bool $called = false;

        public function fetch(string $pandoraUserUuid): ?array
        {
            $this->called = true;

            return ['current_streak' => 99, 'longest_streak' => 99, 'today_in_streak' => true, 'last_login_date' => null];
        }
    };
    app()->instance(GroupStreakClient::class, $stub);

    $user = User::factory()->create();
    // UserObserver auto-fills uuid on save; force back to empty for this test
    // (verifies controller short-circuits when there's no Pandora Core link).
    \DB::table('users')->where('id', $user->id)->update(['pandora_user_uuid' => null]);
    $user->refresh();
    Sanctum::actingAs($user);

    $this->getJson('/api/streak/today')
        ->assertOk()
        ->assertJsonPath('group', null);

    expect($stub->called)->toBeFalse();
});

it('GET /api/streak/today returns group=null when client returns null (disabled / network fail)', function () {
    $stub = new class extends GroupStreakClient
    {
        public function fetch(string $pandoraUserUuid): ?array
        {
            return null;
        }
    };
    app()->instance(GroupStreakClient::class, $stub);

    $user = User::factory()->create(['pandora_user_uuid' => '11111111-2222-3333-4444-555555555555']);
    Sanctum::actingAs($user);

    $this->getJson('/api/streak/today')
        ->assertOk()
        ->assertJsonPath('group', null);
});
