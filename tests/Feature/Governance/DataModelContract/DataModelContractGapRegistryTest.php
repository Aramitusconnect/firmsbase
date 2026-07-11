<?php

namespace Tests\Feature\Governance\DataModelContract;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * Regression test: proves Section 26 updated the EXISTING RLS gap's
 * wording rather than creating a second, duplicate RLS gap entry.
 */
class DataModelContractGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_gap_registry_now_contains_section_25_26_gaps_plus_later_section_gaps(): void
    {
        // 7 Section 25/26 gaps + 2 Section 27 gaps (org_admin_role_missing,
        // emergency_support_access_high_risk_approval_not_wired) + 2
        // Section 28 gaps (seed_data_defaults_and_test_secrets_not_audited,
        // restore_tests_do_not_exercise_real_restore_path) + 2 Section 29
        // gaps (integration_degradation_registry_missing_ai_sms_whatsapp,
        // secret_rotation_schedule_or_reminder_missing) + 2 Section 30
        // gaps (client_facing_payment_receipts_missing,
        // template_pack_per_pack_commercial_differentiation_missing).
        $this->assertCount(21, $this->service->all());
    }

    public function test_rls_gap_exists(): void
    {
        $this->assertTrue($this->service->isTracked('rls_prepared_not_enforced'));
    }

    public function test_rls_gap_severity_is_still_high(): void
    {
        $item = $this->service->byKey('rls_prepared_not_enforced');

        $this->assertSame(GovernanceGapSeverity::High, $item->severity);
    }

    public function test_rls_gap_description_tracks_incomplete_preparation_without_stale_phase_labels(): void
    {
        // Follow-up correction to Section 39A-4A.1: the description no
        // longer hardcodes rollout phases/counts (they go stale the
        // moment a new FORCE-RLS or inventory batch lands), so this
        // test proves the *current* contract — incomplete preparation
        // is still described accurately, current counts are deferred
        // to RowLevelSecurityCoverageMappingService, and none of the
        // old phase labels or stale rollout numbers crept back in.
        $item = $this->service->byKey('rls_prepared_not_enforced');

        $this->assertNotNull($item);

        $this->assertStringContainsString('RowLevelSecurityCoverageMappingService', $item->description);

        $this->assertStringContainsString(
            'still lack FORCE ROW LEVEL SECURITY',
            $item->description,
            'The description must state that some prepared tables remain without FORCE RLS.'
        );
        $this->assertStringContainsString(
            'remain without any prepared RLS policy',
            $item->description,
            'The description must state that additional tenant-owned tables remain without prepared RLS policies.'
        );

        foreach (['Phase 6', 'Phase 7', '18 of the 52', 'other 34', '61 tables total'] as $staleLabel) {
            $this->assertStringNotContainsString(
                $staleLabel,
                $item->description,
                "The description must not reintroduce the stale label \"{$staleLabel}\"."
            );
        }
    }

    public function test_no_duplicate_rls_gap_exists(): void
    {
        $rlsRelatedKeys = array_filter(
            array_map(fn ($item) => $item->key, $this->service->all()),
            fn (string $key) => str_contains($key, 'rls'),
        );

        $this->assertCount(1, $rlsRelatedKeys, 'Expected exactly one RLS-related gap item, found: '.implode(', ', $rlsRelatedKeys));
    }

    public function test_no_severity_other_than_rls_was_changed(): void
    {
        $this->assertSame(GovernanceGapSeverity::High, $this->service->byKey('firm_user_2fa_missing')->severity);
        $this->assertSame(GovernanceGapSeverity::Medium, $this->service->byKey('client_portal_2fa_missing')->severity);
        $this->assertSame(GovernanceGapSeverity::Medium, $this->service->byKey('login_policy_wrappers_missing')->severity);
        $this->assertSame(GovernanceGapSeverity::Medium, $this->service->byKey('signed_document_url_service_missing')->severity);
        $this->assertSame(GovernanceGapSeverity::Low, $this->service->byKey('real_malware_scanning_engine_stubbed')->severity);
        $this->assertSame(GovernanceGapSeverity::Low, $this->service->byKey('auth_admin_override_events_generic_only')->severity);
    }
}
