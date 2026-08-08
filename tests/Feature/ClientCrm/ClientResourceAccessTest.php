<?php

declare(strict_types=1);

namespace Tests\Feature\ClientCrm;

use App\Enums\FirmLeadStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\ClientResource\Actions\AddClientAction;
use App\Filament\Firm\Resources\ClientResource\Pages\EditClient;
use App\Filament\Firm\Resources\ClientResource\Pages\ListClients;
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
 * ClientResourceAccessTest — proves (1) ClientResource has no 'create'
 * route at all (Client::create() can never be reached through this
 * Resource), (2) "+ Add Client" (AddClientAction) never calls
 * Client::create() directly and DOES route through
 * LeadConversionService::convert() — the resulting Client is linked to
 * a Converted FirmLead, exactly this mission's required proof, (3)
 * role-gated visibility of AddClientAction, (4) EditClient only
 * touches the documented safe-field allowlist, and (5) cross-tenant
 * isolation (list query, real RLS proof, and direct-URL/record-id
 * denial), matching MatterResourceAccessTest's established style.
 */
final class ClientResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 0. No Create route exists at all
    // ------------------------------------------------------------

    public function test_client_resource_declares_no_create_page(): void
    {
        $pages = ClientResource::getPages();

        $this->assertArrayNotHasKey('create', $pages);
    }

    // ------------------------------------------------------------
    // 1. canAccess() / canCreate() role ceilings
    // ------------------------------------------------------------

    public function test_view_roles_can_access_the_client_resource(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(ClientResource::canAccess(), "canAccess() failed for role {$role->value}");
        }
    }

    public function test_guest_cannot_access_the_client_resource(): void
    {
        $this->assertFalse(ClientResource::canAccess());
    }

    // ------------------------------------------------------------
    // 2. "+ Add Client" role-gated visibility
    // ------------------------------------------------------------

    public function test_add_client_action_is_visible_for_a_paralegal(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListClients::class));

        $test->assertActionVisible(AddClientAction::getDefaultName());
    }

    public function test_add_client_action_is_hidden_for_a_receptionist(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListClients::class));

        $test->assertActionHidden(AddClientAction::getDefaultName());
    }

    public function test_add_client_action_is_hidden_for_billing_staff(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListClients::class));

        $test->assertActionHidden(AddClientAction::getDefaultName());
    }

    // ------------------------------------------------------------
    // 3. "+ Add Client" NEVER calls Client::create() directly — proves
    //    the lead-intake-then-convert path for real
    // ------------------------------------------------------------

    public function test_add_client_action_creates_a_converted_lead_and_a_properly_linked_client_never_a_bare_client_create(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertSame(0, $this->runWithFirmContext($firm, fn () => Client::query()->count()));
        $this->assertSame(0, $this->runWithFirmContext($firm, fn () => FirmLead::query()->count()));

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(ListClients::class);

            $test->mountAction(AddClientAction::getDefaultName());
            $test->setActionData([
                'intake_name' => 'Pat Prospective',
                'intake_email' => 'pat@example.com',
                'intake_phone' => '555-0199',
                'display_name' => 'Pat Prospective',
                'legal_name' => null,
                'email' => 'pat@example.com',
                'phone' => '555-0199',
                'preferred_language' => 'en',
                'preferred_timezone' => 'America/New_York',
            ]);
            $test->callMountedAction();
            $test->assertHasNoActionErrors();
        });

        $client = $this->runWithFirmContext($firm, fn () => Client::query()->where('display_name', 'Pat Prospective')->first());
        $this->assertNotNull($client, 'AddClientAction must create a real Client row.');

        $lead = $this->runWithFirmContext($firm, fn () => FirmLead::query()->where('name', 'Pat Prospective')->first());
        $this->assertNotNull($lead, 'AddClientAction must create the required intake FirmLead row.');

        // The proof: the Client is linked to a Converted FirmLead —
        // this can only be true if LeadConversionService::convert() ran
        // (the only codepath that ever sets converted_client_id/status).
        $this->assertSame(FirmLeadStatus::Converted, $lead->status);
        $this->assertSame($client->id, $lead->converted_client_id);
        $this->assertNotNull($lead->converted_at);
    }

    public function test_client_model_source_is_never_instantiated_via_create_anywhere_in_the_add_client_action(): void
    {
        // Structural proof, matching this codebase's own
        // "test_manual_sync_dispatch_is_scoped_to_pull_only" static-scan
        // convention: AddClientAction's own source never calls
        // Client::create() — only FirmLead::create() (the intake row)
        // and LeadConversionService::convert() (the one legitimate path
        // to a Client row) appear.
        $source = file_get_contents(app_path('Filament/Firm/Resources/ClientResource/Actions/AddClientAction.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('Client::create', $source);
        $this->assertStringContainsString('FirmLead::create', $source);
        $this->assertStringContainsString('LeadConversionService::class)->convert', $source);
    }

    // ------------------------------------------------------------
    // 4. EditClient — safe-field allowlist only
    // ------------------------------------------------------------

    /**
     * ClientResource::form()'s SCHEMA (not the hydrated Livewire `data`
     * array, which Filament pre-fills from the record's full
     * attributesToArray() regardless of which fields the schema
     * actually declares — a framework behavior, not a safety boundary)
     * is the real allowlist. Structural source scan, matching this
     * codebase's own "test_manual_sync_dispatch_is_scoped_to_pull_only"
     * static-scan convention: only the six documented safe fields are
     * declared as TextInput components.
     */
    public function test_client_resource_form_schema_declares_only_the_documented_safe_field_allowlist(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/ClientResource.php'));
        $this->assertIsString($source);

        preg_match_all('/TextInput::make\(\'([a-z_]+)\'\)/', $source, $matches);
        $declaredFields = $matches[1];

        $this->assertSame(
            ['display_name', 'legal_name', 'email', 'phone', 'preferred_language', 'preferred_timezone'],
            $declaredFields,
        );
    }

    /**
     * Behavioral proof, not just a source scan: even a form submission
     * that supplies a value for a field the schema does NOT declare
     * (portal_status) leaves that column untouched — Schema::getState()
     * only extracts state for declared components, so
     * handleRecordUpdate() never even sees the extra key.
     */
    public function test_edit_client_save_never_touches_a_field_outside_the_declared_schema(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'Original']));
        $originalPortalStatus = $client->portal_status;

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(EditClient::class, ['record' => $client->getRouteKey()]);
            $test->fillForm(['display_name' => 'Updated']);
            // Attempt to smuggle a value for an undeclared field directly
            // into the Livewire component's data property.
            $test->set('data.portal_status', 'active');
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Client::query()->find($client->id));
        $this->assertSame('Updated', $fresh->display_name);
        $this->assertSame($originalPortalStatus, $fresh->portal_status, 'portal_status must never be settable through EditClient.');
    }

    public function test_edit_client_persists_a_safe_field_change(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'Old Name']));

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(EditClient::class, ['record' => $client->getRouteKey()]);
            $test->fillForm(['display_name' => 'New Name']);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Client::query()->find($client->id));
        $this->assertSame('New Name', $fresh->display_name);
    }

    // ------------------------------------------------------------
    // 5. Cross-tenant isolation
    // ------------------------------------------------------------

    public function test_list_page_shows_only_this_firms_clients(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListClients::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$clientA]);
        $test->assertCanNotSeeTableRecords([$clientB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_client_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('clients')->pluck('id')->all());

        $this->assertContains($clientA->id, $visibleIds);
        $this->assertNotContains($clientB->id, $visibleIds, "Firm A's session must never read Firm B's client row.");
    }

    public function test_direct_url_guess_of_another_firms_client_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(ClientResource::getUrl('view', ['record' => $clientB])));

        $response->assertNotFound();
    }

    public function test_direct_edit_url_guess_of_another_firms_client_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(ClientResource::getUrl('edit', ['record' => $clientB])));

        $response->assertNotFound();
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
