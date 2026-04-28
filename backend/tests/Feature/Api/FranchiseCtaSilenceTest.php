<?php

namespace Tests\Feature\Api;

use App\Events\UserOptedOutFranchiseCta;
use App\Models\DodoUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADR-008 UX sensitivity — opt-out flag verification.
 *
 *   - silence on  → bootstrap returns show_franchise_cta=false even if stage=loyalist
 *   - silence off → bootstrap restores normal lifecycle-driven decision
 *   - opt-out fires UserOptedOutFranchiseCta event for downstream listeners
 *
 * Class-style PHPUnit (avoids Pest TestCall noise on $this->actingAs / postJson;
 * mirrors the FunnelDashboardTest convention so we don't grow the baseline).
 */
class FranchiseCtaSilenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.pandora_conversion.base_url', 'http://py-service.test');
        config()->set('services.pandora_conversion.shared_secret', 'test-secret');
        config()->set('services.pandora_conversion.franchise_url', 'https://js-store.com.tw/franchise/consult');
        Cache::flush();

        Http::fake([
            '*/api/v1/users/*/lifecycle' => Http::response(['stage' => 'loyalist'], 200),
            '*/api/v1/internal/events' => Http::response(['accepted' => true], 202),
        ]);
    }

    private function makeUser(string $uuid = 'uuid-silence-001'): User
    {
        $user = User::factory()->create(['pandora_user_uuid' => $uuid]);
        // UserObserver::ensureMirror() should have created the DodoUser row;
        // firstOrCreate is defensive against test ordering.
        DodoUser::firstOrCreate(
            ['pandora_user_uuid' => $uuid],
            ['display_name' => 'Silence Test'],
        );

        return $user;
    }

    public function test_silence_on_hides_franchise_cta_even_when_stage_is_loyalist(): void
    {
        $user = $this->makeUser('uuid-silence-loyalist');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/me/franchise-cta-silence', ['silenced' => true])
            ->assertOk()
            ->assertJsonPath('silenced', true);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonPath('lifecycle.status', 'loyalist')
            ->assertJsonPath('lifecycle.show_franchise_cta', false);
    }

    public function test_silence_off_restores_lifecycle_driven_cta_decision(): void
    {
        $user = $this->makeUser('uuid-silence-toggle');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/me/franchise-cta-silence', ['silenced' => true])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/me/franchise-cta-silence', ['silenced' => false])
            ->assertOk()
            ->assertJsonPath('silenced', false);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/bootstrap')
            ->assertOk()
            ->assertJsonPath('lifecycle.status', 'loyalist')
            ->assertJsonPath('lifecycle.show_franchise_cta', true);
    }

    public function test_persists_silenced_flag_on_dodo_users_with_timestamp(): void
    {
        $user = $this->makeUser('uuid-silence-persist');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/me/franchise-cta-silence', ['silenced' => true])
            ->assertOk();

        $row = DodoUser::query()->whereKey('uuid-silence-persist')->first();
        $this->assertTrue($row->franchise_cta_silenced);
        $this->assertNotNull($row->franchise_cta_silenced_at);
    }

    public function test_clears_silenced_at_when_toggled_off(): void
    {
        $user = $this->makeUser('uuid-silence-clear');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/me/franchise-cta-silence', ['silenced' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/me/franchise-cta-silence', ['silenced' => false]);

        $row = DodoUser::query()->whereKey('uuid-silence-clear')->first();
        $this->assertFalse($row->franchise_cta_silenced);
        $this->assertNull($row->franchise_cta_silenced_at);
    }

    public function test_fires_opt_out_event_on_toggle(): void
    {
        Event::fake([UserOptedOutFranchiseCta::class]);
        $user = $this->makeUser('uuid-silence-event');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/me/franchise-cta-silence', ['silenced' => true])
            ->assertOk();

        Event::assertDispatched(UserOptedOutFranchiseCta::class, function (UserOptedOutFranchiseCta $e): bool {
            return $e->pandoraUserUuid === 'uuid-silence-event' && $e->silenced === true;
        });
    }

    public function test_rejects_request_without_silenced_field(): void
    {
        $user = $this->makeUser('uuid-silence-validate');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/me/franchise-cta-silence', [])
            ->assertStatus(422);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/me/franchise-cta-silence', ['silenced' => true])
            ->assertStatus(401);
    }
}
