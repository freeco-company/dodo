<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FranchiseLeads\FranchiseLeadResource;
use App\Filament\Resources\FranchiseLeads\Pages\ListFranchiseLeads;
use App\Models\DodoUser;
use App\Models\FranchiseLead;
use App\Models\LifecycleOverrideLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament admin /admin/resources/franchise-leads behavior.
 *
 * Class-style PHPUnit (avoids Pest TestCall noise on Filament Livewire).
 */
class FranchiseLeadResourceTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Lead Admin',
            'email' => 'leads-admin@dodo.local',
            'password' => Hash::make('secret-pass'),
        ]);
    }

    private function seedLead(string $uuid, string $status = FranchiseLead::STATUS_NEW): FranchiseLead
    {
        DodoUser::firstOrCreate(['pandora_user_uuid' => $uuid], ['display_name' => 'Lead '.$uuid]);

        return FranchiseLead::create([
            'pandora_user_uuid' => $uuid,
            'source_app' => 'doudou',
            'trigger_event' => 'franchise.cta_click',
            'status' => $status,
        ]);
    }

    public function test_admin_can_list_leads_in_inbox(): void
    {
        $this->seedLead('uuid-list-1');
        $this->seedLead('uuid-list-2');

        $this->actingAs($this->makeAdmin());

        Livewire::test(ListFranchiseLeads::class)
            ->assertCanSeeTableRecords(
                FranchiseLead::query()->whereIn('pandora_user_uuid', ['uuid-list-1', 'uuid-list-2'])->get(),
            );
    }

    public function test_inbox_hides_silenced_leads_by_default(): void
    {
        $this->seedLead('uuid-active', FranchiseLead::STATUS_NEW);
        $silencedLead = $this->seedLead('uuid-quiet', FranchiseLead::STATUS_SILENCED);

        $this->actingAs($this->makeAdmin());

        Livewire::test(ListFranchiseLeads::class)
            ->assertCanNotSeeTableRecords([$silencedLead]);
    }

    public function test_inbox_subheading_warns_admin_not_to_auto_message(): void
    {
        $this->actingAs($this->makeAdmin());

        $response = $this->get(FranchiseLeadResource::getUrl('index'));

        $response->assertOk();
        $response->assertSee('內部分段資料');
        $response->assertSee('不要自動發訊');
        $response->assertSee('人工', false);
    }

    public function test_admin_can_mark_lead_as_contacting(): void
    {
        $lead = $this->seedLead('uuid-contacting', FranchiseLead::STATUS_NEW);

        $this->actingAs($this->makeAdmin());

        Livewire::test(ListFranchiseLeads::class)
            ->callTableAction('mark_contacting', $lead);

        $this->assertSame(FranchiseLead::STATUS_CONTACTING, $lead->fresh()->status);
    }

    public function test_admin_can_mark_lead_as_contacted_and_sets_timestamp(): void
    {
        $lead = $this->seedLead('uuid-contacted', FranchiseLead::STATUS_CONTACTING);

        $this->actingAs($this->makeAdmin());

        Livewire::test(ListFranchiseLeads::class)
            ->callTableAction('mark_contacted', $lead);

        $fresh = $lead->fresh();
        $this->assertSame(FranchiseLead::STATUS_CONTACTED, $fresh->status);
        $this->assertNotNull($fresh->contacted_at);
    }

    public function test_admin_can_override_lifecycle_stage(): void
    {
        config()->set('services.pandora_conversion.base_url', 'https://conversion.test');
        config()->set('services.pandora_conversion.shared_secret', 'test-secret');

        $lead = $this->seedLead('uuid-override-1', FranchiseLead::STATUS_CONTACTING);
        Http::fake([
            // Cache miss → LifecycleClient hits this first
            '*/api/v1/users/uuid-override-1/lifecycle' => Http::response(['stage' => 'loyalist'], 200),
            // Then admin override
            '*/internal/admin/users/uuid-override-1/lifecycle/transition' => Http::response([
                'id' => 1, 'from_status' => 'loyalist', 'to_status' => 'applicant',
            ], 201),
        ]);

        $admin = $this->makeAdmin();
        $this->actingAs($admin);

        Livewire::test(ListFranchiseLeads::class)
            ->callTableAction('override_lifecycle', $lead, [
                'to_status' => 'applicant',
                'reason' => 'BD confirmed by phone — should already be applicant',
            ]);

        $log = LifecycleOverrideLog::query()->firstOrFail();
        $this->assertSame('uuid-override-1', $log->pandora_user_uuid);
        $this->assertSame('loyalist', $log->from_status);
        $this->assertSame('applicant', $log->to_status);
        $this->assertSame($admin->email, $log->actor_email);
        $this->assertTrue($log->succeeded);
        $this->assertNull($log->error);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/internal/admin/users/uuid-override-1/lifecycle/transition')
            && $request['actor'] === $admin->email
            && $request['reason'] === 'BD confirmed by phone — should already be applicant'
            && $request['to_status'] === 'applicant');
    }

    public function test_override_action_logs_failure_when_py_service_errors(): void
    {
        config()->set('services.pandora_conversion.base_url', 'https://conversion.test');
        config()->set('services.pandora_conversion.shared_secret', 'test-secret');

        $lead = $this->seedLead('uuid-override-fail', FranchiseLead::STATUS_NEW);
        Http::fake([
            '*/api/v1/users/uuid-override-fail/lifecycle' => Http::response(['stage' => 'visitor'], 200),
            '*/internal/admin/users/uuid-override-fail/lifecycle/transition' => Http::response(
                ['detail' => 'simulated upstream failure'],
                500,
            ),
        ]);

        $this->actingAs($this->makeAdmin());

        Livewire::test(ListFranchiseLeads::class)
            ->callTableAction('override_lifecycle', $lead, [
                'to_status' => 'applicant',
                'reason' => 'should fail and be audited',
            ]);

        $log = LifecycleOverrideLog::query()->firstOrFail();
        $this->assertFalse($log->succeeded);
        $this->assertNotNull($log->error);
        $this->assertStringContainsString('lifecycle override failed', (string) $log->error);
    }

    public function test_override_action_validates_reason_min_length(): void
    {
        $lead = $this->seedLead('uuid-override-reason', FranchiseLead::STATUS_NEW);
        $this->actingAs($this->makeAdmin());

        Livewire::test(ListFranchiseLeads::class)
            ->callTableAction('override_lifecycle', $lead, [
                'to_status' => 'applicant',
                'reason' => 'x', // too short, < 5 chars
            ])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(0, LifecycleOverrideLog::query()->count());
    }

    public function test_status_filter_narrows_to_new_only(): void
    {
        $newLead = $this->seedLead('uuid-filter-new', FranchiseLead::STATUS_NEW);
        $contactedLead = $this->seedLead('uuid-filter-contacted', FranchiseLead::STATUS_CONTACTED);

        $this->actingAs($this->makeAdmin());

        Livewire::test(ListFranchiseLeads::class)
            ->filterTable('status', FranchiseLead::STATUS_NEW)
            ->assertCanSeeTableRecords([$newLead])
            ->assertCanNotSeeTableRecords([$contactedLead]);
    }
}
