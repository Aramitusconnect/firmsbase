<?php

namespace Tests\Feature\Governance\QualityGates;

use App\Enums\GovernanceMappingStatus;
use App\Services\PermissionMatrixMappingService;
use App\Services\RowLevelSecurityCoverageMappingService;
use App\Services\TestCoverageMappingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestCoverageMappingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED_KEYS = [
        'tenant_isolation_general',
        'tenant_isolation_broken_scope_caught_by_rls',
        'role_permission_org_boundaries',
        'entitlement_inheritance_override_precedence',
        'client_portal_access',
        'conflict_check_firm_default_org_opt_in',
        'consent_enforcement_sms_whatsapp_email',
        'document_security_virus_scanning',
        'upload_download_authorization',
        'payment_classification',
        'payment_plan_lifecycle',
        'manual_payment_double_submit',
        'stripe_payment_webhook_idempotency',
        'platform_billing_separation_consolidation_usage_attribution',
        'trust_ledger_concurrency',
        'legal_specialist_trust_route_blocking',
        'ai_permission_approval_retrieval_prompt_injection',
        'email_deliverability_gate',
        'queue_scheduler_health',
        'import_export_tenant_isolation',
        'template_versioning_form_edition_retirement',
        'fleet_migration_offline_license_validation',
        'restore_tests',
        'accessibility_client_facing_flows',
    ];

    private TestCoverageMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TestCoverageMappingService;
    }

    public function test_all_twenty_four_test_group_keys_are_declared_explicitly(): void
    {
        $items = $this->service->all();

        $this->assertCount(24, $items);

        $declaredKeys = array_map(fn ($item) => $item->item_key, $items);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required test group key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate test group key(s) found.');
    }

    public function test_by_key_resolves_every_declared_key(): void
    {
        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertNotNull($this->service->byKey($key), "byKey() could not resolve: {$key}");
        }

        $this->assertNull($this->service->byKey('does_not_exist'));
    }

    /**
     * Section 39A-3L Stage B (Checkpoints 22-34) activated permanent
     * FORCE ROW LEVEL SECURITY on all originally-prepared tables —
     * $enforcementActive below is therefore now genuinely TRUE, unlike
     * when this test was first written (its name and the guard below
     * are historical). That does NOT make this control fully
     * Implemented: firm_settings being forced was always a proxy for
     * "is ANY enforcement active," not the actual gating condition —
     * the real reason this control remains PartiallyImplemented is
     * that additional tenant-owned tables discovered by inventory
     * sweeps still have zero RLS preparation at all, so a broken
     * scope against any of THOSE remains uncaught (see the
     * rls_prepared_not_enforced gap's own still-open component, and
     * RowLevelSecurityCoverageMappingService::missingPreparedTables()
     * for the live, non-hardcoded count). This assertion is therefore
     * unconditional now, rather than gated behind $enforcementActive —
     * gating it there made this test silently perform zero assertions
     * the instant enforcement activated, exactly the failure mode this
     * rewrite closes.
     */
    public function test_rls_broken_scope_is_not_implemented_because_enforcement_is_inactive(): void
    {
        $row = DB::selectOne(
            'select relforcerowsecurity from pg_class where relname = ?',
            ['firm_settings']
        );
        $enforcementActive = (bool) $row->relforcerowsecurity;

        $this->assertTrue(
            $enforcementActive,
            'firm_settings must have permanent FORCE ROW LEVEL SECURITY active — Section 39A-3L Stage B is complete.'
        );

        // Section 39A-5 Wave 11 (the final wave of the 60-table rollout)
        // closed the last remaining uncovered tenant-owned table, so
        // this test's original premise ("at least one uncovered table
        // exists") no longer holds. TestCoverageMappingService::all()
        // was updated in the same pass to cite the OTHER,
        // still-genuinely-open reasons (offboarding_exports' uncertain
        // classification, the registered-but-unimplemented
        // cross-firm-pivot-mismatch remediation task, the firms
        // root-table policy design, and the support-access policy shape
        // design) rather than the now-resolved uncovered-table count.
        $uncoveredCount = count((new RowLevelSecurityCoverageMappingService)->missingPreparedTables());
        $this->assertSame(0, $uncoveredCount, 'Wave 11 must have closed every remaining uncovered tenant-owned table.');

        $item = $this->service->byKey('tenant_isolation_broken_scope_caught_by_rls');

        $this->assertNotSame(
            GovernanceMappingStatus::Implemented,
            $item->status,
            'This control remains PartiallyImplemented for reasons unrelated to table-coverage completeness — see the notes for the specific still-open items.'
        );

        $this->assertStringContainsString(
            (string) $uncoveredCount,
            $item->notes,
            'The generated notes must contain the current, dynamically-derived uncovered-table count, not a hard-coded literal.'
        );
        $this->assertStringNotContainsString(
            'enforcement is inactive',
            strtolower($item->notes),
            'The notes must not claim enforcement is inactive — it is now genuinely active for every tenant-owned table.'
        );
        $this->assertStringContainsString(
            'cross-firm-pivot-mismatch',
            $item->notes,
            'The notes must cite an actual, still-open reason this control is not fully Implemented, now that table coverage itself is complete.'
        );
    }

    public function test_role_permission_org_boundaries_is_not_implemented_while_org_admin_is_missing(): void
    {
        $item = $this->service->byKey('role_permission_org_boundaries');

        $this->assertNotSame(GovernanceMappingStatus::Implemented, $item->status);

        $orgAdmin = (new PermissionMatrixMappingService)->byKey('org_admin');
        $this->assertSame(GovernanceMappingStatus::NotFound, $orgAdmin->status);
    }

    public function test_every_mapping_includes_owning_evidence_or_notes(): void
    {
        foreach ($this->service->all() as $item) {
            $this->assertTrue(
                $item->owning_class !== null || ! empty($item->notes),
                "Item {$item->item_key} has neither an owning_class nor notes.",
            );
            $this->assertNotEmpty($item->notes, "Item {$item->item_key} should have explanatory notes.");
        }
    }

    public function test_implemented_partial_not_found_and_not_applicable_partition_all_items(): void
    {
        $implemented = array_map(fn ($i) => $i->item_key, $this->service->implemented());
        $partial = array_map(fn ($i) => $i->item_key, $this->service->partial());
        $notFound = array_map(fn ($i) => $i->item_key, $this->service->notFound());
        $notApplicable = array_map(fn ($i) => $i->item_key, $this->service->notApplicableYet());

        $union = array_merge($implemented, $partial, $notFound, $notApplicable);

        $this->assertCount(24, array_unique($union));
        $this->assertCount(24, $union, 'Buckets must not overlap.');
    }
}
