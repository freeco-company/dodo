<?php

namespace Tests\Feature\Filament;

use App\Models\User;
use App\Services\Conversion\FunnelMetricsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 漏斗 dashboard /admin/funnel + FunnelMetricsClient 行為驗證。
 *
 * 使用 PHPUnit class-style（而非 Pest closure）以避開
 * `Pest\PendingCalls\TestCall::actingAs/get` 的 phpstan noise，
 * 因為本 PR 不增加 baseline 條目。
 */
class FunnelDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Funnel Admin',
            'email' => 'funnel-admin@dodo.local',
            'password' => Hash::make('secret-pass'),
        ]);
    }

    public function test_admin_loads_funnel_dashboard_with_stub_data(): void
    {
        config()->set('services.pandora_conversion.base_url', '');
        config()->set('services.pandora_conversion.shared_secret', '');

        $response = $this->actingAs($this->makeAdmin())->get('/admin/funnel');

        $response->assertOk();
        $response->assertSee('加盟漏斗');
        // stub fixture: visitor 1,000 / registered 620
        $response->assertSee('1,000');
        $response->assertSee('620');
        $response->assertSee('stub fixture');
    }

    public function test_non_admin_user_is_forbidden(): void
    {
        $regular = User::factory()->create([
            'email' => 'plain@example.com',
            'membership_tier' => 'public',
        ]);

        $this->actingAs($regular)
            ->get('/admin/funnel')
            ->assertForbidden();
    }

    public function test_dashboard_shows_live_metrics_when_py_service_responds_200(): void
    {
        config()->set('services.pandora_conversion.base_url', 'https://py.test');
        config()->set('services.pandora_conversion.shared_secret', 'shh');

        Http::fake([
            'py.test/api/v1/funnel/metrics' => Http::response([
                'stages' => [
                    'visitor' => 4242,
                    'registered' => 2100,
                    'engaged' => 800,
                    'loyalist' => 333,
                    'applicant' => 44,
                    'franchisee' => 11,
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->makeAdmin())->get('/admin/funnel');

        $response->assertOk();
        $response->assertSee('4,242');
        $response->assertSee('2,100');
    }

    public function test_metrics_client_returns_zero_stages_on_5xx(): void
    {
        config()->set('services.pandora_conversion.base_url', 'https://py.test');
        config()->set('services.pandora_conversion.shared_secret', 'shh');

        Http::fake([
            'py.test/api/v1/funnel/metrics' => Http::response('boom', 503),
        ]);

        $client = app(FunnelMetricsClient::class);
        $result = $client->fetch();

        $this->assertSame('error', $result['source']);
        $this->assertSame(0, $result['stages']['visitor']);
        $this->assertSame(0, $result['stages']['franchisee']);
    }

    public function test_metrics_client_returns_stub_when_not_configured(): void
    {
        config()->set('services.pandora_conversion.base_url', '');
        config()->set('services.pandora_conversion.shared_secret', '');

        $result = app(FunnelMetricsClient::class)->fetch();

        $this->assertSame('stub', $result['source']);
        $this->assertSame(1000, $result['stages']['visitor']);
        $this->assertSame(7, $result['stages']['franchisee']);
        $this->assertSame(
            ['visitor', 'registered', 'engaged', 'loyalist', 'applicant', 'franchisee'],
            array_keys($result['stages']),
        );
    }

    public function test_metrics_client_sends_x_internal_secret_header(): void
    {
        config()->set('services.pandora_conversion.base_url', 'https://py.test');
        config()->set('services.pandora_conversion.shared_secret', 'top-secret');

        Http::fake([
            'py.test/api/v1/funnel/metrics' => Http::response(['stages' => []], 200),
        ]);

        app(FunnelMetricsClient::class)->fetch();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Internal-Secret', 'top-secret')
                && str_ends_with($request->url(), '/api/v1/funnel/metrics');
        });
    }
}
