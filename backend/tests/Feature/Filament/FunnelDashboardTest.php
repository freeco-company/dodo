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
        // ADR-008 stub fixture: visitor 1,000 / loyalist 95 / applicant 18 /
        // franchisee_self_use 12 / franchisee_active 3
        $response->assertSee('1,000');
        $response->assertSee('Self-Use');
        $response->assertSee('Active');
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
                    // ADR-008 5-stage shape
                    'visitor' => 4242,
                    'loyalist' => 800,
                    'applicant' => 333,
                    'franchisee_self_use' => 80,
                    'franchisee_active' => 11,
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->makeAdmin())->get('/admin/funnel');

        $response->assertOk();
        $response->assertSee('4,242');
        $response->assertSee('800');
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
        $this->assertSame(0, $result['stages']['franchisee_active']);
    }

    public function test_metrics_client_returns_stub_when_not_configured(): void
    {
        config()->set('services.pandora_conversion.base_url', '');
        config()->set('services.pandora_conversion.shared_secret', '');

        $result = app(FunnelMetricsClient::class)->fetch();

        $this->assertSame('stub', $result['source']);
        $this->assertSame(1000, $result['stages']['visitor']);
        $this->assertSame(3, $result['stages']['franchisee_active']);
        $this->assertSame(
            ['visitor', 'loyalist', 'applicant', 'franchisee_self_use', 'franchisee_active'],
            array_keys($result['stages']),
        );
    }

    public function test_widget_survives_legacy_py_service_payload(): void
    {
        // ADR-008 §6 merge order note: 若 py-service 還沒升級，仍回舊 stage 名，
        // 我們要保證 widget 顯示 0（不炸 / 不爆 stack）並讓 admin 知道狀況。
        config()->set('services.pandora_conversion.base_url', 'https://py.test');
        config()->set('services.pandora_conversion.shared_secret', 'shh');

        Http::fake([
            'py.test/api/v1/funnel/metrics' => Http::response([
                'stages' => [
                    // 全部是舊 6-stage 名稱 — normalizeStages() 應該丟掉
                    'visitor' => 999,
                    'registered' => 600,
                    'engaged' => 300,
                    'loyalist' => 80,
                    'applicant' => 10,
                    'franchisee' => 2,
                ],
            ], 200),
        ]);

        $client = app(FunnelMetricsClient::class);
        $result = $client->fetch();

        // visitor / loyalist / applicant 是新舊都有 → 應該保留
        $this->assertSame(999, $result['stages']['visitor']);
        $this->assertSame(80, $result['stages']['loyalist']);
        $this->assertSame(10, $result['stages']['applicant']);
        // 新 stage 在舊 payload 缺席 → 應該 fill 0，不炸
        $this->assertSame(0, $result['stages']['franchisee_self_use']);
        $this->assertSame(0, $result['stages']['franchisee_active']);
        // 並且不能殘留 legacy key（避免 widget render 出錯）
        $this->assertArrayNotHasKey('registered', $result['stages']);
        $this->assertArrayNotHasKey('engaged', $result['stages']);
        $this->assertArrayNotHasKey('franchisee', $result['stages']);
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
