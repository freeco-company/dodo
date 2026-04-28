<?php

namespace Tests\Feature\Auth;

use App\Models\DodoUser;
use App\Models\User;
use App\Services\Identity\IdentityClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase F prep — `auth.dual` middleware (SanctumOrPandoraJwt) 行為驗證。
 *
 * 5 cases per task spec：
 *   1. sanctum token 通過
 *   2. platform JWT 通過
 *   3. 兩個都沒帶 → 401
 *   4. 亂碼 token → 401（JWT 不過 + sanctum 不認）
 *   5. JWT 過期 → 401（IdentityClient 回 null + 沒有 sanctum fallback）
 */
class SanctumOrPandoraJwtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Define a throwaway protected route that just echoes the resolved user
        // and the auth strategy attribute set by the middleware.
        Route::middleware('auth.dual')->get(
            '/__test__/dual-auth-probe',
            fn (Request $request) => response()->json([
                'auth_strategy' => $request->attributes->get('auth_strategy'),
                'user_id' => optional($request->user())->getKey(),
                'user_class' => $request->user() === null ? null : $request->user()::class,
            ])
        );
    }

    public function test_sanctum_token_passes(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/__test__/dual-auth-probe');

        $response->assertOk();
        $response->assertJson([
            'auth_strategy' => 'sanctum',
            'user_id' => $user->getKey(),
            'user_class' => User::class,
        ]);
    }

    public function test_platform_jwt_passes_and_resolves_dodo_user(): void
    {
        $dodoUser = DodoUser::query()->create([
            'pandora_user_uuid' => '01900000-0000-7000-8000-aaaaaaaaaaaa',
        ]);

        // Plain is final and lcobucci/jwt 5.x has no usable interface; we pass a
        // stdClass placeholder because the middleware only stashes it in
        // request attributes and never calls a method on it.
        $tokenStub = new \stdClass;

        $this->mock(IdentityClient::class, function ($mock) use ($dodoUser, $tokenStub) {
            $mock->shouldReceive('resolveFromJwt')
                ->once()
                ->with('valid-platform-jwt')
                ->andReturn(['user' => $dodoUser, 'token' => $tokenStub]);
        });

        $response = $this->withHeaders(['Authorization' => 'Bearer valid-platform-jwt'])
            ->getJson('/__test__/dual-auth-probe');

        $response->assertOk();
        $response->assertJson([
            'auth_strategy' => 'pandora_jwt',
            'user_class' => DodoUser::class,
        ]);
    }

    public function test_no_credentials_returns_401(): void
    {
        $this->mock(IdentityClient::class, function ($mock) {
            // 不帶 Authorization 時 middleware 不會呼叫 resolveFromJwt
            $mock->shouldNotReceive('resolveFromJwt');
        });

        $response = $this->getJson('/__test__/dual-auth-probe');
        $response->assertStatus(401);
    }

    public function test_garbage_bearer_token_returns_401(): void
    {
        $this->mock(IdentityClient::class, function ($mock) {
            $mock->shouldReceive('resolveFromJwt')
                ->once()
                ->with('this-is-not-a-jwt')
                ->andReturn(null);
        });

        $response = $this->withHeaders(['Authorization' => 'Bearer this-is-not-a-jwt'])
            ->getJson('/__test__/dual-auth-probe');

        // platform JWT fail + 此 bearer 不對應任何 sanctum token → 401
        $response->assertStatus(401);
    }

    public function test_expired_jwt_with_no_sanctum_fallback_returns_401(): void
    {
        // Simulate IdentityClient rejecting an expired token (returns null).
        $this->mock(IdentityClient::class, function ($mock) {
            $mock->shouldReceive('resolveFromJwt')
                ->once()
                ->with('expired.jwt.token')
                ->andReturn(null);
        });

        $response = $this->withHeaders(['Authorization' => 'Bearer expired.jwt.token'])
            ->getJson('/__test__/dual-auth-probe');

        $response->assertStatus(401);
    }
}
