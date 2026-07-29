<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialEvidence;

use App\Enums\PlatformRoleCode;
use App\Filament\Resources\ProviderKillSwitchResource;
use App\Filament\Resources\ProviderKillSwitchResource\Pages\ListProviderKillSwitches;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Models\ProviderKillSwitch;
use App\Integrations\Services\PlatformPlaidItemDirectoryService;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FinancialEvidencePlatformAdminAuthorizationTest — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on").
 * Covers: PlatformAdmin authorization onto the Plaid oversight
 * surfaces, genuine query/response-shape redaction proofs (never
 * transaction-level data), and ReadOnlyAuditor denial of mutation.
 *
 * PROMINENT FINDING: ProviderKillSwitchResource — the ONE deliberate
 * PlatformAdmin write surface in this checkpoint — does not enforce
 * this codebase's own established "blanket rule 9: a read_only_auditor
 * may never mutate data" (PlatformStaffAccessPolicyService::canMutate()).
 * Traced directly against the live resource source
 * (app/Filament/Resources/ProviderKillSwitchResource.php): canAccess()/
 * canViewAny() call only canAccessIntegrationOversight() (a READ gate,
 * CLIENT_AND_MATTER_DATA_ROLES = SuperAdmin/PlatformAdmin/SupportAgent/
 * ImplementationSpecialist), and the createKillSwitch()/toggleAction()
 * mutating Action closures check ONLY `$admin instanceof PlatformAdmin`
 * — canMutate() is never called anywhere in this file. An admin who
 * holds BOTH PlatformAdmin (passes the view gate) AND ReadOnlyAuditor
 * (this codebase's own blanket "never mutate, regardless of any other
 * role also held" rule) can still successfully create and toggle a
 * kill switch. Contrast with the already-shipped, CORRECT precedent
 * for the identical scenario:
 * tests/Feature/PlatformAdmin/PlanResourceTest::test_archive_action_is_denied_for_a_read_only_auditor_even_with_super_admin(),
 * which this test's shape deliberately mirrors. Written to assert the
 * CORRECT, expected behavior (no mutation) — it fails against the real
 * code, which is the point; flagged prominently in the test-writer
 * report as a genuine, secondary defect (not the reclassification
 * chain, which is sound).
 */
class FinancialEvidencePlatformAdminAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    // ------------------------------------------------------------
    // PROMINENT FINDING — ReadOnlyAuditor kill-switch mutation gap
    // ------------------------------------------------------------

    public function test_a_read_only_auditor_who_also_holds_platform_admin_cannot_create_a_kill_switch(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::PlatformAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $countBefore = ProviderKillSwitch::query()->count();

        $test = Livewire::test(ListProviderKillSwitches::class);
        $test->mountTableAction('createKillSwitch');
        $test->setTableActionData([
            'level' => ProviderKillSwitch::LEVEL_PRODUCT,
            'target' => 'transactions',
            'reason' => 'Testing whether a read-only auditor can mutate this.',
        ]);
        $test->callMountedTableAction();

        $countAfter = ProviderKillSwitch::query()->count();

        $this->assertSame(
            $countBefore,
            $countAfter,
            'A read_only_auditor must never be able to create a kill switch, regardless of also holding PlatformAdmin — '
                .'canMutate() must be enforced by createKillSwitch(), mirroring PlanResourceTest\'s own established precedent.'
        );
    }

    public function test_a_read_only_auditor_who_also_holds_platform_admin_cannot_toggle_a_kill_switch(): void
    {
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::PlatformAdmin);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $killSwitch = ProviderKillSwitch::query()->create([
            'provider_key' => ProviderKey::Plaid->value,
            'scope_type' => ProviderKillSwitch::SCOPE_PLATFORM,
            'scope_id' => null,
            'level' => ProviderKillSwitch::LEVEL_PRODUCT,
            'target' => 'transactions',
            'suspended' => false,
            'reason' => 'Fixture.',
        ]);

        $test = Livewire::test(ListProviderKillSwitches::class);
        $test->mountTableAction('toggle', $killSwitch->getKey());
        $test->callMountedTableAction();

        $killSwitch->refresh();

        $this->assertFalse(
            $killSwitch->suspended,
            'A read_only_auditor must never be able to toggle a kill switch\'s suspended state, regardless of also holding PlatformAdmin.'
        );
    }

    public function test_a_plain_read_only_auditor_with_no_other_role_cannot_even_view_the_kill_switch_resource(): void
    {
        // Contrast case: WITHOUT also holding PlatformAdmin,
        // canAccessIntegrationOversight() correctly denies access
        // outright (ReadOnlyAuditor is not in CLIENT_AND_MATTER_DATA_ROLES) —
        // confirming the finding above is specifically about the
        // dual-role case, not a wholesale denial failure.
        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::ReadOnlyAuditor);
        $this->actingAs($admin, 'platform_admin');

        $this->assertFalse(ProviderKillSwitchResource::canAccess());
    }

    // ------------------------------------------------------------
    // Redaction — PlatformAdmin oversight never exposes
    // transaction-level data
    // ------------------------------------------------------------

    public function test_platform_plaid_item_directory_service_response_shape_never_includes_a_dollar_amount_or_merchant_field(): void
    {
        $firm = Firm::factory()->create();
        $plaidProvider = IntegrationProvider::query()->where('code', ProviderKey::Plaid->value)->first();
        $connection = $this->runWithFirmContext($firm, fn () => FirmIntegration::factory()->forFirm($firm)->forProvider($plaidProvider)->create());

        $admin = PlatformAdmin::factory()->create(['is_active' => true]);
        app(PlatformRoleService::class)->grant($admin, PlatformRoleCode::PlatformAdmin);

        $rows = app(PlatformPlaidItemDirectoryService::class)->listAll($admin, $firm->id);

        $this->assertNotEmpty($rows);

        $row = $rows->first();
        foreach (['amount_cents', 'amount', 'merchant_name', 'account_number', 'transaction_id', 'balance'] as $forbiddenKey) {
            $this->assertArrayNotHasKey($forbiddenKey, $row, "PlatformPlaidItemDirectoryService's response row must never carry '{$forbiddenKey}' — PlatformAdmin oversight is redacted/summary-only.");
        }
    }

    public function test_no_platform_admin_service_or_resource_directly_queries_a_financial_evidence_fact_table(): void
    {
        // Structural, source-level proof mirroring the design's own
        // §3 "Redaction discipline" — scans every PlatformAdmin Plaid
        // Filament class + both oversight read services for a direct
        // reference to any financial_evidence_* fact/snapshot table.
        $files = array_merge(
            glob(app_path('Filament/Resources').'/PlaidItemOversightResource.php') ?: [],
            glob(app_path('Filament/Resources/PlaidItemOversightResource').'/**/*.php') ?: [],
            glob(app_path('Filament/Pages').'/Plaid*.php') ?: [],
            glob(app_path('Integrations/Services').'/PlatformPlaid*.php') ?: [],
        );

        $this->assertNotEmpty($files);

        $forbiddenTables = [
            'financial_evidence_transactions',
            'financial_evidence_income_records',
            'financial_evidence_liabilities',
            'financial_evidence_investment_records',
            'financial_evidence_identity_records',
            'financial_evidence_snapshots',
            'FinancialEvidenceTransaction::',
            'FinancialEvidenceIncomeRecord::',
            'FinancialEvidenceLiability::',
            'FinancialEvidenceInvestmentRecord::',
            'FinancialEvidenceIdentityRecord::',
            'FinancialEvidenceSnapshot::',
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertNotFalse($source);

            foreach ($forbiddenTables as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $source,
                    basename($file)." must never reference '{$needle}' — PlatformAdmin oversight is redacted/summary-only, never transaction-level."
                );
            }
        }
    }

    public function test_sandbox_or_live_status_column_is_a_single_global_config_value_never_per_firm_secret_data(): void
    {
        $oversightResource = file_get_contents(app_path('Filament/Resources/PlaidItemOversightResource.php'));
        $this->assertNotFalse($oversightResource);

        // Confirms the design's own binding statement: sandbox/live mode
        // is read from config(), never from a per-firm credential/secret
        // column.
        $this->assertStringNotContainsString('client_secret', $oversightResource);
        $this->assertStringNotContainsString('access_token', $oversightResource);
    }
}
