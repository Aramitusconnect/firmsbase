<?php

declare(strict_types=1);

namespace Tests\Feature\Governance;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Livewire\FirmTopbar;
use App\Filament\Firm\Resources\ClientResource;
use App\Filament\Firm\Resources\ClientResource\Actions\AddClientAction;
use App\Filament\Firm\Resources\ClientResource\Pages\ListClients;
use App\Filament\Firm\Resources\CommunicationConsentResource;
use App\Filament\Firm\Resources\CommunicationConsentResource\Actions\CaptureConsentAction;
use App\Filament\Firm\Resources\CommunicationConsentResource\Pages\ListCommunicationConsents;
use App\Filament\Firm\Resources\ContactResource;
use App\Filament\Firm\Resources\DeadlineResource;
use App\Filament\Firm\Resources\DocumentChaseRuleResource;
use App\Filament\Firm\Resources\DocumentRequestResource;
use App\Filament\Firm\Resources\ExpenseResource;
use App\Filament\Firm\Resources\FirmIntegrationResource;
use App\Filament\Firm\Resources\FirmLeadResource;
use App\Filament\Firm\Resources\FirmUserResource;
use App\Filament\Firm\Resources\MatterResource;
use App\Filament\Firm\Resources\PaymentResource;
use App\Filament\Firm\Resources\PaymentResource\Actions\RecordPaymentAction;
use App\Filament\Firm\Resources\PaymentResource\Pages\ListPayments;
use App\Filament\Firm\Resources\PlaidItemResource;
use App\Filament\Firm\Resources\TaskResource;
use App\Filament\Firm\Resources\TimeEntryResource;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FirmWorkspaceCompletenessGuardTest — the permanent regression guard
 * for the whole Firm Workspace Master Mission (Firm Feature Manifest
 * Tier 1-H). This is NOT a test of any single module's business logic
 * (each module already has its own dedicated access/behavior test
 * suite — ClientCrm/, TasksDeadlines/, TimeExpenses/, Payments/,
 * Communications/, Documents/); it exists solely to make a future
 * merge that silently shrinks the Firm panel back toward
 * "Dashboard + Matters", accidentally leaks a platform-admin page into
 * the firm guard, or accidentally exposes a still-BLOCKED feature
 * (real file uploads, AI, a real third-party integration) fail loudly
 * and immediately — exactly the kind of regression that is otherwise
 * invisible until a human happens to click through the sidebar.
 *
 * Deliberately does NOT touch anything under
 * tests/Feature/Security/RlsForceRollout/ — this is pure Filament
 * panel-registration/authorization-surface testing, not tenant
 * isolation testing (RLS is covered exhaustively elsewhere).
 */
final class FirmWorkspaceCompletenessGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. Core Firm resources stay registered.
    // ------------------------------------------------------------

    public function test_firm_panel_resolves_and_registers_the_full_tier1_resource_roster(): void
    {
        $panel = Filament::getPanel('firm');

        $this->assertNotNull($panel);
        $this->assertSame('firm', $panel->getId());

        $resources = $panel->getResources();

        $expectedResources = [
            MatterResource::class,
            ClientResource::class,
            ContactResource::class,
            FirmLeadResource::class,
            TaskResource::class,
            DeadlineResource::class,
            TimeEntryResource::class,
            ExpenseResource::class,
            PaymentResource::class,
            CommunicationConsentResource::class,
            DocumentRequestResource::class,
            DocumentChaseRuleResource::class,
            FirmIntegrationResource::class,
            PlaidItemResource::class,
            FirmUserResource::class,
        ];

        foreach ($expectedResources as $resourceClass) {
            $this->assertContains(
                $resourceClass,
                $resources,
                "Firm panel must register {$resourceClass} — the Firm sidebar has silently shrunk.",
            );
        }

        $this->assertContains(
            Dashboard::class,
            $panel->getPages(),
            'Firm panel must register its Dashboard page.',
        );
    }

    // ------------------------------------------------------------
    // 2. Paid modules remain gated for a zero-entitlement firm.
    // ------------------------------------------------------------

    public function test_paid_modules_are_inaccessible_for_a_firm_with_zero_entitlement_rows(): void
    {
        $firm = Firm::factory()->create(); // deliberately zero entitlement rows
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertFalse(FirmIntegrationResource::canAccess());
        $this->assertFalse(FirmIntegrationResource::shouldRegisterNavigation());

        $this->assertFalse(PlaidItemResource::canAccess());
        $this->assertFalse(PlaidItemResource::shouldRegisterNavigation());

        // Expenses ARE entitlement-gated too (module_catalog code
        // `expenses`, confirmed by direct source read of
        // ExpenseResource::isFirmEntitled() /
        // AccountingEntitlementPolicyService — not merely a role
        // ceiling), so it belongs in this same guard.
        $this->assertFalse(ExpenseResource::canAccess());
        $this->assertFalse(ExpenseResource::shouldRegisterNavigation());
    }

    public function test_paid_modules_become_accessible_once_the_firm_is_entitled(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);
        app(EntitlementService::class)->setForSource($firm, 'plaid', EntitlementSource::AdminOverride, true);
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->assertTrue(FirmIntegrationResource::canAccess());
        $this->assertTrue(PlaidItemResource::canAccess());
        $this->assertTrue(ExpenseResource::canAccess());
    }

    public function test_expense_direct_route_hit_is_blocked_for_a_disentitled_firm(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(ExpenseResource::getUrl('index')));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    // ------------------------------------------------------------
    // 3. Platform Admin pages never leak into the Firm panel.
    // ------------------------------------------------------------

    public function test_firm_panel_resource_and_page_discovery_is_scoped_exactly_to_the_firm_tree(): void
    {
        $panel = Filament::getPanel('firm');

        $this->assertSame(
            [app_path('Filament/Firm/Resources')],
            $panel->getResourceDirectories(),
            'Firm panel must discover resources from exactly one directory: app/Filament/Firm/Resources.',
        );
        $this->assertSame(
            ['App\Filament\Firm\Resources'],
            $panel->getResourceNamespaces(),
        );

        $this->assertSame(
            [app_path('Filament/Firm/Pages')],
            $panel->getPageDirectories(),
            'Firm panel must discover pages from exactly one directory: app/Filament/Firm/Pages.',
        );
        $this->assertSame(
            ['App\Filament\Firm\Pages'],
            $panel->getPageNamespaces(),
        );
    }

    public function test_no_registered_firm_panel_resource_or_page_lives_outside_the_firm_namespace(): void
    {
        $panel = Filament::getPanel('firm');

        foreach ($panel->getResources() as $resourceClass) {
            $this->assertStringStartsWith(
                'App\Filament\Firm\Resources\\',
                $resourceClass,
                "{$resourceClass} is registered on the firm panel but lives outside App\\Filament\\Firm\\Resources — a platform-admin leak.",
            );
        }

        foreach ($panel->getPages() as $pageClass) {
            $this->assertTrue(
                $pageClass === Dashboard::class || str_starts_with($pageClass, 'App\Filament\Firm\Pages\\'),
                "{$pageClass} is registered on the firm panel but is neither the stock Dashboard nor under App\\Filament\\Firm\\Pages — a platform-admin leak.",
            );
        }
    }

    // ------------------------------------------------------------
    // 4. BLOCKED features do not accidentally appear as usable.
    // ------------------------------------------------------------

    public function test_blocked_feature_resources_do_not_exist_under_the_firm_resources_tree(): void
    {
        $mustNotExist = [
            // Real file-upload-backed Document resource — no storage
            // pipeline exists anywhere in this codebase (Firm Feature
            // Manifest §5). Only DocumentRequest/DocumentChaseRule
            // (workflow, never file storage) may exist.
            'App\Filament\Firm\Resources\DocumentResource',
            'App\Filament\Firm\Resources\FileResource',

            // AI is a governance/audit layer with zero real AI capability
            // (Firm Feature Manifest §14) — no firm-facing AI resource or
            // page may exist yet.
            'App\Filament\Firm\Resources\AiResource',
            'App\Filament\Firm\Resources\AiUsageResource',
            'App\Filament\Firm\Resources\AiUsageEventResource',
            'App\Filament\Firm\Resources\AiApprovalRequestResource',
            'App\Filament\Firm\Pages\AiAssistantPage',

            // No real QuickBooks/Clio/LawPay/SMS/WhatsApp integration
            // exists (Firm Feature Manifest §15/§16) — a dedicated
            // resource implying one works must not exist.
            'App\Filament\Firm\Resources\QuickBooksResource',
            'App\Filament\Firm\Resources\ClioResource',
            'App\Filament\Firm\Resources\LawPayResource',
            'App\Filament\Firm\Resources\SmsResource',
            'App\Filament\Firm\Resources\WhatsAppResource',
        ];

        foreach ($mustNotExist as $class) {
            $this->assertFalse(
                class_exists($class),
                "{$class} must not exist yet — its backend prerequisite is not built (see Firm Feature Manifest). If this now legitimately exists, it must ship together with its own prerequisite backend work and this guard must be updated deliberately, not accidentally.",
            );
        }
    }

    // ------------------------------------------------------------
    // 5. Manual Add/Create entry points exist and are reachable by at
    //    least one authorized role (FirmOwner sits at or above every
    //    domain's create ceiling — confirmed by direct source read of
    //    every *AccessPolicyService involved below).
    // ------------------------------------------------------------

    public function test_client_add_action_exists_and_is_reachable_by_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(ListClients::class);
            $test->assertActionExists(AddClientAction::getDefaultName());
            $test->assertActionVisible(AddClientAction::getDefaultName());
        });
    }

    public function test_contact_create_page_is_reachable_by_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(ContactResource::getUrl('create')));
        $response->assertOk();
    }

    public function test_firm_lead_create_page_is_reachable_by_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(FirmLeadResource::getUrl('create')));
        $response->assertOk();
    }

    public function test_task_create_page_is_reachable_by_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TaskResource::getUrl('create')));
        $response->assertOk();
    }

    public function test_deadline_create_page_is_reachable_by_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(DeadlineResource::getUrl('create')));
        $response->assertOk();
    }

    public function test_time_entry_create_page_is_reachable_by_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TimeEntryResource::getUrl('create')));
        $response->assertOk();
    }

    public function test_expense_create_page_is_reachable_by_an_entitled_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'expenses', EntitlementSource::AdminOverride, true);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(ExpenseResource::getUrl('create')));
        $response->assertOk();
    }

    public function test_payment_record_action_exists_and_is_reachable_by_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(ListPayments::class);
            $test->assertActionExists(RecordPaymentAction::getDefaultName());
            $test->assertActionVisible(RecordPaymentAction::getDefaultName());
        });
    }

    public function test_communication_consent_capture_action_exists_and_is_reachable_by_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(ListCommunicationConsents::class);
            $test->assertActionExists(CaptureConsentAction::getDefaultName());
            $test->assertActionVisible(CaptureConsentAction::getDefaultName());
        });
    }

    public function test_document_request_create_page_is_reachable_by_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $response = $this->runWithFirmContext($firm, fn () => $this->get(DocumentRequestResource::getUrl('create')));
        $response->assertOk();
    }

    // ------------------------------------------------------------
    // 6. Global Quick Add menu — must reuse the exact same Action
    //    classes as each resource's own creation flow, and must
    //    independently respect each domain's own authorization.
    // ------------------------------------------------------------

    public function test_firm_panel_uses_the_quick_add_topbar_component(): void
    {
        $this->assertSame(FirmTopbar::class, Filament::getPanel('firm')->getTopbarLivewireComponent());
    }

    public function test_quick_add_client_action_is_the_exact_add_client_action_class_and_visible_to_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(FirmTopbar::class);
            $this->assertInstanceOf(AddClientAction::class, $test->instance()->quickAddClientAction());
            $test->assertActionVisible('quickAddClient');
        });
    }

    public function test_quick_add_payment_action_is_the_exact_record_payment_action_class_and_visible_to_firm_owner(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(FirmTopbar::class);
            $this->assertInstanceOf(RecordPaymentAction::class, $test->instance()->quickAddPaymentAction());
            $test->assertActionVisible('quickAddPayment');
        });
    }

    public function test_quick_add_client_action_is_hidden_for_a_role_below_the_client_management_ceiling(): void
    {
        $firm = Firm::factory()->create();
        // Receptionist is below ClientCrmAccessPolicyService's
        // CLIENT_MANAGEMENT_ROLES ceiling that canConvertLead() checks
        // (confirmed by direct source read) — the same ceiling
        // AddClientAction's own ->visible() closure checks, so this
        // independently proves Quick Add never widens authorization
        // beyond what the original "+ Add Client" action already
        // allows.
        $this->actingAsRole($firm, FirmUserRole::Receptionist);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(FirmTopbar::class);
            $test->assertActionHidden('quickAddClient');
        });
    }

    public function test_quick_add_menu_does_not_include_matter_trust_invoice_or_ai_items(): void
    {
        // Structural, not a UI-string scan: Matter creation has no safe
        // general-purpose service yet (Firm Feature Manifest §2), and
        // Trust/Invoices/AI have no Firm-facing UI at all — the Quick
        // Add partial must not reference any of those resource/action
        // classes.
        $source = file_get_contents(resource_path('views/filament/firm/quick-add-menu.blade.php'));
        $this->assertIsString($source);

        $forbiddenReferences = [
            'MatterResource::getUrl(\'create\')',
            'TrustAccountResource',
            'TrustLedgerResource',
            'InvoiceResource',
            'PaymentPlanResource',
            'AiResource',
            'AiUsageResource',
        ];

        foreach ($forbiddenReferences as $reference) {
            $this->assertStringNotContainsString($reference, $source, "Quick Add menu must never reference {$reference}.");
        }
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

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
