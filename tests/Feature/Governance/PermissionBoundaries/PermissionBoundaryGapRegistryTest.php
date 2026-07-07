<?php

namespace Tests\Feature\Governance\PermissionBoundaries;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * PermissionBoundaryGapRegistryTest — proves Section 27 added exactly
 * two new gap items to the EXISTING ComplianceGapRegistryService,
 * without duplicating the RLS gap or any other Section 25/26 gap.
 */
class PermissionBoundaryGapRegistryTest extends TestCase
{
    private const PRE_EXISTING_GAP_KEYS = [
        'rls_prepared_not_enforced',
        'firm_user_2fa_missing',
        'client_portal_2fa_missing',
        'login_policy_wrappers_missing',
        'signed_document_url_service_missing',
        'real_malware_scanning_engine_stubbed',
        'auth_admin_override_events_generic_only',
    ];

    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_registry_contains_existing_gaps_plus_the_two_new_section_27_gaps(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        foreach (self::PRE_EXISTING_GAP_KEYS as $key) {
            $this->assertContains($key, $keys, "Pre-existing gap missing: {$key}");
        }

        $this->assertContains('org_admin_role_missing', $keys);
        $this->assertContains('emergency_support_access_high_risk_approval_not_wired', $keys);

        // Section 28, Section 29, and Section 30 subsequently added 2 more gaps each on top of these.
        $this->assertCount(18, $keys);
    }

    public function test_org_admin_gap_severity_is_medium(): void
    {
        $item = $this->service->byKey('org_admin_role_missing');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::Medium, $item->severity);
    }

    public function test_emergency_support_access_gap_severity_is_high(): void
    {
        $item = $this->service->byKey('emergency_support_access_high_risk_approval_not_wired');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::High, $item->severity);
    }

    public function test_no_duplicate_rls_gap_exists(): void
    {
        $rlsRelatedKeys = array_filter(
            array_map(fn ($item) => $item->key, $this->service->all()),
            fn (string $key) => str_contains($key, 'rls'),
        );

        $this->assertCount(1, $rlsRelatedKeys);
    }

    public function test_no_duplicate_gap_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate gap key(s) found: '.implode(', ', array_diff_assoc($keys, array_unique($keys))));
    }

    public function test_exact_count_is_eleven(): void
    {
        $this->assertCount(18, $this->service->all());
    }
}
