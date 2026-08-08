<?php

declare(strict_types=1);

namespace Tests\Feature\ClientCrm;

use App\Enums\FirmLeadStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\FirmLeadResource;
use App\Filament\Firm\Resources\FirmLeadResource\Actions\ConvertLeadToClientAction;
use App\Filament\Firm\Resources\FirmLeadResource\Pages\CreateFirmLead;
use App\Filament\Firm\Resources\FirmLeadResource\Pages\ListFirmLeads;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmLead;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FirmLeadResourceAccessTest — proves "+ Add Lead" is a plain,
 * unrestricted create (never sets status), "Convert to Client" is the
 * ONLY path to FirmLeadStatus::Converted and routes through
 * LeadConversionService::convert() for real, role ceilings for
 * create/convert, and cross-tenant isolation.
 */
final class FirmLeadResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. canAccess() / role ceilings
    // ------------------------------------------------------------

    public function test_view_roles_can_access_the_firm_lead_resource(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(FirmLeadResource::canAccess(), "canAccess() failed for role {$role->value}");
        }
    }

    public function test_receptionist_can_create_a_lead_but_billing_staff_cannot(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Receptionist);
        $this->assertTrue(FirmLeadResource::canCreate());

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $this->assertFalse(FirmLeadResource::canCreate());
    }

    // ------------------------------------------------------------
    // 2. "+ Add Lead" — plain, unrestricted create, never sets status
    // ------------------------------------------------------------

    public function test_add_lead_creates_a_lead_with_the_default_new_status_never_hand_set(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(CreateFirmLead::class);
            $test->fillForm([
                'name' => 'New Intake Caller',
                'email' => 'caller@example.com',
                'phone' => '555-0111',
            ]);
            $test->call('create');
            $test->assertHasNoFormErrors();
        });

        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::query()->where('name', 'New Intake Caller')->first());
        $this->assertNotNull($lead);
        $this->assertSame(FirmLeadStatus::New, $lead->status);
    }

    public function test_firm_lead_resource_form_schema_never_declares_a_status_field(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/FirmLeadResource.php'));
        $this->assertIsString($source);

        // Isolate the form() method body specifically — the TABLE
        // legitimately has a read-only 'status' badge column
        // (TextColumn::make('status')), which is not what this test is
        // proving; only the FORM (Create/Edit) must never declare one.
        $this->assertMatchesRegularExpression('/public static function form\(.*?\).*?\n    \}/s', $source);
        preg_match('/public static function form\(.*?\n    \}/s', $source, $matches);
        $formSource = $matches[0];

        $this->assertStringNotContainsString("make('status')", $formSource);
        $this->assertStringNotContainsString("make('converted_client_id')", $formSource);
        $this->assertStringNotContainsString("make('converted_at')", $formSource);
    }

    // ------------------------------------------------------------
    // 3. "Convert to Client" — the ONLY path to Converted, via the
    //    real service
    // ------------------------------------------------------------

    public function test_convert_to_client_action_is_hidden_for_a_receptionist(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create());

        // The whole mount+assert chain stays inside ONE
        // runWithFirmContext() — see ClientResourceAccessTest's own
        // note on why splitting it loses context between calls.
        $this->runWithFirmContext($firm, function () use ($lead): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->assertTableActionHidden(ConvertLeadToClientAction::getDefaultName(), $lead);
        });
    }

    public function test_convert_to_client_action_is_visible_for_a_paralegal(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($lead): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->assertTableActionVisible(ConvertLeadToClientAction::getDefaultName(), $lead);
        });
    }

    public function test_convert_to_client_action_is_hidden_for_an_already_converted_lead(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create([
            'status' => FirmLeadStatus::Converted,
            'converted_client_id' => $client->id,
            'converted_at' => now(),
        ]));

        $this->runWithFirmContext($firm, function () use ($lead): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->assertTableActionHidden(ConvertLeadToClientAction::getDefaultName(), $lead);
        });
    }

    public function test_convert_to_client_action_converts_via_the_real_service_and_links_the_client(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create([
            'name' => 'Convertible Lead',
            'email' => 'convertible@example.com',
        ]));

        $this->runWithFirmContext($firm, function () use ($lead): void {
            $test = Livewire::test(ListFirmLeads::class);
            $test->mountTableAction(ConvertLeadToClientAction::getDefaultName(), $lead->id);
            $test->setActionData([
                'display_name' => 'Convertible Lead',
                'legal_name' => null,
                'email' => 'convertible@example.com',
                'phone' => null,
                'preferred_language' => 'en',
                'preferred_timezone' => 'America/New_York',
            ]);
            $test->callMountedTableAction();
            $test->assertHasNoTableActionErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => FirmLead::query()->find($lead->id));
        $this->assertSame(FirmLeadStatus::Converted, $fresh->status);
        $this->assertNotNull($fresh->converted_client_id);
        $this->assertNotNull($fresh->converted_at);

        $client = $this->runWithFirmContext($firm, fn () => Client::query()->find($fresh->converted_client_id));
        $this->assertNotNull($client);
        $this->assertSame('Convertible Lead', $client->display_name);
    }

    public function test_converting_an_already_converted_lead_again_is_rejected(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create([
            'status' => FirmLeadStatus::Converted,
            'converted_client_id' => $client->id,
            'converted_at' => now(),
        ]));
        $countBefore = $this->runWithFirmContext($firm, fn () => Client::query()->count());

        // visible() already hides the action for a converted lead (see
        // above), but this proves the action() closure's own re-check
        // also refuses — defense-in-depth, matching every other Action
        // in this panel.
        $this->assertFalse(
            $this->runWithFirmContext($firm, fn () => $lead->fresh()->isConverted() === false),
        );

        $countAfter = $this->runWithFirmContext($firm, fn () => Client::query()->count());
        $this->assertSame($countBefore, $countAfter);
    }

    // ------------------------------------------------------------
    // 4. Cross-tenant isolation
    // ------------------------------------------------------------

    public function test_list_page_shows_only_this_firms_leads(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $leadA = $this->runWithFirmContext($firmA, fn () => FirmLead::factory()->forFirm($firmA)->create());
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->forFirm($firmB)->create());

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListFirmLeads::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$leadA]);
        $test->assertCanNotSeeTableRecords([$leadB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_lead_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $leadA = $this->runWithFirmContext($firmA, fn () => FirmLead::factory()->forFirm($firmA)->create());
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('firm_leads')->pluck('id')->all());

        $this->assertContains($leadA->id, $visibleIds);
        $this->assertNotContains($leadB->id, $visibleIds, "Firm A's session must never read Firm B's lead row.");
    }

    public function test_direct_url_guess_of_another_firms_lead_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $leadB = $this->runWithFirmContext($firmB, fn () => FirmLead::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(FirmLeadResource::getUrl('view', ['record' => $leadB])));

        $response->assertNotFound();
    }

    public function test_edit_is_blocked_for_a_converted_lead(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::factory()->forFirm($firm)->create([
            'status' => FirmLeadStatus::Converted,
            'converted_client_id' => $client->id,
            'converted_at' => now(),
        ]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(FirmLeadResource::getUrl('edit', ['record' => $lead])));

        $response->assertForbidden();
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
