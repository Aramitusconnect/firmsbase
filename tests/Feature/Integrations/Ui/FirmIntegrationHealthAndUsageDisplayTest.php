<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Pages\IntegrationUsagePage;
use App\Filament\Firm\Resources\FirmIntegrationResource\Pages\ViewFirmIntegration;
use App\Integrations\Data\SanitizedHealthDiagnostic;
use App\Integrations\Enums\ConnectionStatus;
use App\Integrations\Enums\HealthSummaryState;
use App\Integrations\Models\FirmIntegration;
use App\Integrations\Services\HealthStateService;
use App\Integrations\Services\IntegrationUsageSummaryService;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * FirmIntegrationHealthAndUsageDisplayTest — Checkpoint 10 (frozen-
 * design-post-security-review.md §6, §11.2).
 *
 * FORMERLY-DISCOVERED BLOCKER, NOW FIXED (health display only):
 * `ViewFirmIntegration`'s "Health" infolist Section used to be
 * unrenderable via `Livewire::test()` because the page itself could not
 * mount, for the same confirmed Filament framework bug documented
 * throughout this checkpoint's Ui test suite (see
 * `FirmIntegrationConnectionLifecycleActionsTest`'s own precise proof
 * of the fix). `test_view_firm_integration_health_section_renders_a_recorded_success_and_a_recorded_credential_error()`
 * below is genuine, real `Livewire::test()`-driven proof that the
 * Health section now renders real `HealthStateService` data end to
 * end — it was previously a self-documented placeholder asserting the
 * mount throws. The remaining health-display tests continue to prove
 * correctness directly against `HealthStateService::summaryFor()` (the
 * exact data source `ViewFirmIntegration::infolist()`'s Health section
 * binds to) plus a structural confirmation that the infolist source
 * only ever reads `HealthStateService`'s own sanitized fields, never a
 * raw diagnostic internal — this is read/render-only coverage, so it is
 * unaffected by the separate, still-open Livewire-action/tenant-context
 * gap documented in `FirmIntegrationConnectionLifecycleActionsTest`'s
 * own class docblock (that gap only affects mutating ACTIONS, a second
 * Livewire round-trip beyond the initial mount/render this file relies
 * on).
 *
 * `IntegrationUsagePage` is a STANDALONE page with no RelationManagers
 * at all, so it is NOT affected by that bug — it is fully exercised via
 * genuine `Livewire::test()` below.
 *
 * SCOPE NOTE (flagged per task instructions, not silently worked
 * around): the task's own framing asks this file to confirm the usage
 * page "correctly reflects RetentionGovernanceRegistryService's
 * NOT_CONFIGURED_FAIL_SAFE status for usage records without fabricating
 * a number." Reading the actual shipped `IntegrationUsagePage.php` and
 * `IntegrationUsageSummaryService.php` (both read in full for this
 * checkpoint) confirms NEITHER file references
 * `RetentionGovernanceRegistryService` at all — the honest empty state
 * is achieved purely because `integration_usage_records` is genuinely
 * empty (frozen design §6's own framing), not because the page
 * consults retention-governance configuration status. This is a
 * mismatch between the task's expectation and the as-built production
 * code, not a gap in this test file — tests below prove the ACTUAL
 * as-built honest-empty-state behavior, and this mismatch is reported
 * in this checkpoint's final report rather than asserted here as if it
 * were true.
 */
final class FirmIntegrationHealthAndUsageDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 0. Page mount — genuine Livewire coverage of the Health section
    //    (the mount-blocking Filament framework bug is now fixed)
    // ------------------------------------------------------------

    public function test_view_firm_integration_health_section_renders_a_recorded_success_and_a_recorded_credential_error(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        app(HealthStateService::class)->recordSuccess($connection->id, $firm->id);

        $healthyTest = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid])
        );

        $healthyTest->assertOk();
        $healthyTest->assertSee(HealthSummaryState::Healthy->value);

        app(HealthStateService::class)->recordCredentialError(
            $connection->id,
            $firm->id,
            new SanitizedHealthDiagnostic(
                SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
                SanitizedHealthDiagnostic::OPERATION_PULL_SYNC,
                401,
            )
        );

        $erroredTest = $this->runWithFirmContext(
            $firm,
            fn () => Livewire::test(ViewFirmIntegration::class, ['record' => $connection->uuid])
        );

        $erroredTest->assertOk();
        $erroredTest->assertSee('category=credential_error', false);
        $erroredTest->assertDontSee('Exception');
    }

    // ------------------------------------------------------------
    // 1. Health display — fallback via HealthStateService directly
    //    (the exact data source ViewFirmIntegration's infolist binds to)
    // ------------------------------------------------------------

    public function test_health_summary_reflects_a_recorded_success(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);

        app(HealthStateService::class)->recordSuccess($connection->id, $firm->id);

        $summary = $this->runWithFirmContext($firm, fn () => app(HealthStateService::class)->summaryFor($connection->fresh()));

        $this->assertSame(HealthSummaryState::Healthy, $summary->summaryState);
        $this->assertSame(0, $summary->consecutiveFailures);
        $this->assertNotNull($summary->lastSuccessAt);
        $this->assertNull($summary->sanitizedDiagnosticSummary);
    }

    public function test_health_summary_reflects_a_recorded_credential_error_with_a_sanitized_diagnostic_never_a_raw_one(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);

        app(HealthStateService::class)->recordCredentialError(
            $connection->id,
            $firm->id,
            new SanitizedHealthDiagnostic(
                SanitizedHealthDiagnostic::CATEGORY_CREDENTIAL_ERROR,
                SanitizedHealthDiagnostic::OPERATION_PULL_SYNC,
                401,
            )
        );

        $summary = $this->runWithFirmContext($firm, fn () => app(HealthStateService::class)->summaryFor($connection->fresh()));

        $this->assertNotSame(HealthSummaryState::Healthy, $summary->summaryState);
        $this->assertSame(1, $summary->consecutiveFailures);
        $this->assertNotNull($summary->sanitizedDiagnosticSummary);

        // The sanitized summary must be built ONLY from the closed,
        // structured SanitizedHealthDiagnostic vocabulary — never a raw
        // provider response body, header, exception message, or stack
        // trace fragment.
        $this->assertStringContainsString('category=credential_error', $summary->sanitizedDiagnosticSummary);
        $this->assertStringContainsString('operation=pull_sync', $summary->sanitizedDiagnosticSummary);
        $this->assertDoesNotMatchRegularExpression('/Exception|Stack trace|#0 /', $summary->sanitizedDiagnosticSummary);
    }

    public function test_a_connection_with_no_health_row_yet_reflects_an_honest_never_checked_summary(): void
    {
        $firm = $this->entitledFirm();
        $connection = $this->connectionFor($firm);

        $summary = $this->runWithFirmContext($firm, fn () => app(HealthStateService::class)->summaryFor($connection));

        $this->assertNull($summary->lastSuccessAt);
        $this->assertNull($summary->lastFailureAt);
        $this->assertSame(0, $summary->consecutiveFailures);
    }

    public function test_view_firm_integration_infolist_source_only_ever_reads_health_state_service_sanitized_fields(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/FirmIntegrationResource/Pages/ViewFirmIntegration.php'));
        $this->assertIsString($source);

        // Every health entry must route through HealthStateService::summaryFor(),
        // never a raw IntegrationConnectionHealth column read.
        $this->assertStringContainsString('app(HealthStateService::class)->summaryFor($record)->summaryState', $source);
        $this->assertStringContainsString('app(HealthStateService::class)->summaryFor($record)->consecutiveFailures', $source);
        $this->assertStringContainsString('app(HealthStateService::class)->summaryFor($record)->sanitizedDiagnosticSummary', $source);

        // Never a raw model bind of IntegrationConnectionHealth.
        $this->assertStringNotContainsString('IntegrationConnectionHealth::', $source);
    }

    // ------------------------------------------------------------
    // 2. IntegrationUsagePage — genuine Livewire::test() coverage
    //    (no RelationManagers involved, unaffected by the bug above)
    // ------------------------------------------------------------

    public function test_usage_page_renders_the_honest_empty_state_when_no_usage_has_been_recorded(): void
    {
        $firm = $this->entitledFirm();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(IntegrationUsagePage::class));

        $test->assertOk();
        $test->assertSee('No usage has been recorded yet');
        // The frozen design's explicit ban: never a fabricated "$0
        // used" framing that would imply a real zero rather than an
        // absence of measurement.
        $test->assertDontSee('$0 used');
    }

    public function test_usage_page_is_reachable_by_billing_staff(): void
    {
        $firm = $this->entitledFirm();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(IntegrationUsagePage::class));

        $test->assertOk();
    }

    public function test_usage_page_is_not_reachable_by_a_role_without_can_view_usage(): void
    {
        $firm = $this->entitledFirm();
        $this->actingAsRole($firm, FirmUserRole::Attorney);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(IntegrationUsagePage::class));

        $test->assertForbidden();
    }

    public function test_usage_summary_service_returns_genuinely_empty_when_no_records_exist(): void
    {
        $firm = $this->entitledFirm();

        $summaries = $this->runWithFirmContext($firm, fn () => app(IntegrationUsageSummaryService::class)->summariesForFirm($firm->id));

        $this->assertCount(0, $summaries);
    }

    public function test_usage_summary_service_never_writes_to_integration_usage_records_it_is_read_only(): void
    {
        $source = file_get_contents(app_path('Integrations/Services/IntegrationUsageSummaryService.php'));
        $this->assertIsString($source);

        $this->assertStringNotContainsString('->create(', $source);
        $this->assertStringNotContainsString('->insert(', $source);
        $this->assertStringNotContainsString('->update(', $source);
    }

    public function test_scope_note_retention_governance_registry_service_is_not_actually_referenced_by_the_usage_page_or_service(): void
    {
        // See this class's own docblock scope note: documents the
        // as-built reality (no RetentionGovernanceRegistryService
        // integration exists) rather than silently asserting a
        // behavior the shipped code does not have.
        $pageSource = file_get_contents(app_path('Filament/Firm/Pages/IntegrationUsagePage.php'));
        $serviceSource = file_get_contents(app_path('Integrations/Services/IntegrationUsageSummaryService.php'));

        $this->assertIsString($pageSource);
        $this->assertIsString($serviceSource);
        $this->assertStringNotContainsString('RetentionGovernanceRegistryService', $pageSource);
        $this->assertStringNotContainsString('RetentionGovernanceRegistryService', $serviceSource);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private function entitledFirm(): Firm
    {
        $firm = Firm::factory()->create();
        app(EntitlementService::class)->setForSource($firm, 'integration', EntitlementSource::AdminOverride, true);

        return $firm;
    }

    private function connectionFor(Firm $firm): FirmIntegration
    {
        $this->runWithFirmContext($firm, fn () => TenantEncryptionKey::factory()->forFirm($firm)->create());

        return $this->runWithFirmContext(
            $firm,
            fn () => FirmIntegration::factory()->forFirm($firm)->create([
                'external_account_id' => null,
                'status' => ConnectionStatus::Active->value,
            ])
        );
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
