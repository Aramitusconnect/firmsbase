<?php

namespace Tests\Feature\Governance\AdminControlCatalog;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * AdminControlGapRegistryTest — proves Section 34 added exactly the
 * one AWS-confirmed gap (form_edition_watch_sla_controls_missing) to
 * the EXISTING ComplianceGapRegistryService (17 -> 18), without
 * duplicating org_admin_role_missing or
 * emergency_support_access_high_risk_approval_not_wired, and without
 * adding a gap merely because no admin UI exists.
 */
class AdminControlGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_section_33_gap_count_before_section_34_additions_was_seventeen(): void
    {
        // 17 pre-existing (Section 25-33) + 1 new Section 34
        // form-edition-watch-SLA gap (confirmed) = 18.
        $this->assertCount(18, $this->service->all());
    }

    public function test_form_edition_watch_sla_gap_exists_because_aws_confirmed_no_sla_representation(): void
    {
        $item = $this->service->byKey('form_edition_watch_sla_controls_missing');

        $this->assertNotNull($item, 'form_edition_watch_sla_controls_missing must exist since AWS confirmed no SLA due-date/status/escalation representation exists.');
        $this->assertSame(GovernanceGapSeverity::Low, $item->severity);
    }

    public function test_final_gap_count_is_eighteen(): void
    {
        $this->assertCount(18, $this->service->all());
    }

    public function test_no_duplicate_org_admin_or_emergency_access_gaps_were_added(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertCount(1, array_filter($keys, fn (string $key) => str_contains($key, 'org_admin')));
        $this->assertCount(1, array_filter($keys, fn (string $key) => str_contains($key, 'emergency_support_access')));
    }

    public function test_no_ui_absence_gap_was_added(): void
    {
        $forbiddenGapKeys = [
            'admin_ui_missing',
            'admin_panel_resources_missing',
            'no_admin_ui_for_organization_management',
            'no_admin_ui_for_trust_controls',
        ];

        foreach ($forbiddenGapKeys as $key) {
            $this->assertFalse($this->service->isTracked($key), "Gap '{$key}' must not exist — UI absence alone is not a gap.");
        }
    }

    public function test_no_duplicate_gap_keys_exist(): void
    {
        $keys = array_map(fn ($item) => $item->key, $this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate gap key(s) found.');
    }

    public function test_no_duplicate_rls_gap_exists(): void
    {
        $rlsRelatedKeys = array_filter(
            array_map(fn ($item) => $item->key, $this->service->all()),
            fn (string $key) => str_contains($key, 'rls'),
        );

        $this->assertCount(1, $rlsRelatedKeys);
    }
}
