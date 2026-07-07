<?php

namespace Tests\Feature\Governance\CrossCutting;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

class ComplianceGapRegistryServiceTest extends TestCase
{
    private const REQUIRED_GAP_KEYS = [
        'rls_prepared_not_enforced',
        'firm_user_2fa_missing',
        'client_portal_2fa_missing',
        'login_policy_wrappers_missing',
        'signed_document_url_service_missing',
        'real_malware_scanning_engine_stubbed',
        'auth_admin_override_events_generic_only',
        'org_admin_role_missing',
        'emergency_support_access_high_risk_approval_not_wired',
        'seed_data_defaults_and_test_secrets_not_audited',
        'restore_tests_do_not_exercise_real_restore_path',
    ];

    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_exactly_eleven_gap_items_are_declared(): void
    {
        $items = $this->service->all();

        $this->assertCount(11, $items);

        $declaredKeys = array_map(fn ($item) => $item->key, $items);

        foreach (self::REQUIRED_GAP_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required gap item: {$key}");
        }
    }

    public function test_every_item_has_a_severity_and_suggested_owning_gate(): void
    {
        foreach ($this->service->all() as $item) {
            $this->assertInstanceOf(GovernanceGapSeverity::class, $item->severity);
            $this->assertNotEmpty($item->suggested_owning_gate);
        }
    }

    public function test_by_severity_filters_correctly(): void
    {
        $high = $this->service->bySeverity(GovernanceGapSeverity::High);
        $this->assertCount(3, $high);
        $this->assertContains('rls_prepared_not_enforced', array_map(fn ($i) => $i->key, $high));
        $this->assertContains('firm_user_2fa_missing', array_map(fn ($i) => $i->key, $high));
        $this->assertContains('emergency_support_access_high_risk_approval_not_wired', array_map(fn ($i) => $i->key, $high));

        $medium = $this->service->bySeverity(GovernanceGapSeverity::Medium);
        $this->assertCount(6, $medium);
        $this->assertContains('org_admin_role_missing', array_map(fn ($i) => $i->key, $medium));
        $this->assertContains('seed_data_defaults_and_test_secrets_not_audited', array_map(fn ($i) => $i->key, $medium));
        $this->assertContains('restore_tests_do_not_exercise_real_restore_path', array_map(fn ($i) => $i->key, $medium));

        $low = $this->service->bySeverity(GovernanceGapSeverity::Low);
        $this->assertCount(2, $low);
    }

    public function test_is_tracked_works(): void
    {
        $this->assertTrue($this->service->isTracked('rls_prepared_not_enforced'));
        $this->assertFalse($this->service->isTracked('does_not_exist'));
    }

    public function test_by_key_returns_the_matching_item_or_null(): void
    {
        $item = $this->service->byKey('real_malware_scanning_engine_stubbed');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceGapSeverity::Low, $item->severity);

        $this->assertNull($this->service->byKey('does_not_exist'));
    }

    public function test_auth_admin_override_item_is_low_severity_and_recommends_no_second_audit_system(): void
    {
        $item = $this->service->byKey('auth_admin_override_events_generic_only');

        $this->assertSame(GovernanceGapSeverity::Low, $item->severity);
        $this->assertStringContainsString('no second audit system recommended', $item->suggested_owning_gate);
    }
}
