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
        'integration_degradation_registry_missing_ai_sms_whatsapp',
        'secret_rotation_schedule_or_reminder_missing',
    ];

    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_exactly_thirteen_gap_items_are_declared(): void
    {
        $items = $this->service->all();

        $this->assertCount(18, $items);

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
        $this->assertCount(4, $high);
        $this->assertContains('rls_prepared_not_enforced', array_map(fn ($i) => $i->key, $high));
        $this->assertContains('firm_user_2fa_missing', array_map(fn ($i) => $i->key, $high));
        $this->assertContains('emergency_support_access_high_risk_approval_not_wired', array_map(fn ($i) => $i->key, $high));
        $this->assertContains('trust_ledger_entry_posting_actor_not_guaranteed', array_map(fn ($i) => $i->key, $high));

        $medium = $this->service->bySeverity(GovernanceGapSeverity::Medium);
        $this->assertCount(9, $medium);
        $this->assertContains('org_admin_role_missing', array_map(fn ($i) => $i->key, $medium));
        $this->assertContains('seed_data_defaults_and_test_secrets_not_audited', array_map(fn ($i) => $i->key, $medium));
        $this->assertContains('restore_tests_do_not_exercise_real_restore_path', array_map(fn ($i) => $i->key, $medium));
        $this->assertContains('integration_degradation_registry_missing_ai_sms_whatsapp', array_map(fn ($i) => $i->key, $medium));
        $this->assertContains('client_facing_payment_receipts_missing', array_map(fn ($i) => $i->key, $medium));
        $this->assertContains('template_pack_per_pack_commercial_differentiation_missing', array_map(fn ($i) => $i->key, $medium));

        $low = $this->service->bySeverity(GovernanceGapSeverity::Low);
        $this->assertCount(5, $low);
        $this->assertContains('secret_rotation_schedule_or_reminder_missing', array_map(fn ($i) => $i->key, $low));
        $this->assertContains('ai_approval_request_lifecycle_states_incomplete', array_map(fn ($i) => $i->key, $low));
        $this->assertContains('form_edition_watch_sla_controls_missing', array_map(fn ($i) => $i->key, $low));
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
