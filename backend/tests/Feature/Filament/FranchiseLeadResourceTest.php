<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\FranchiseLeads\FranchiseLeadResource;
use App\Filament\Resources\FranchiseLeads\Pages\ListFranchiseLeads;
use App\Models\DodoUser;
use App\Models\FranchiseLead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
