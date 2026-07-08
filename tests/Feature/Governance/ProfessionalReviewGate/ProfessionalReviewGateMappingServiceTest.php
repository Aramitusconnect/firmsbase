<?php

namespace Tests\Feature\Governance\ProfessionalReviewGate;

use App\Enums\GovernanceMappingStatus;
use App\Services\ProfessionalReviewGateMappingService;
use App\ValueObjects\GovernanceMappingResult;
use Tests\TestCase;

class ProfessionalReviewGateMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'plan.no_duplicate_phase_contracts',
        'plan.no_revision_style_sections_override_contracts',
        'security.no_hidden_navigation_only_security',
        'payments.no_payment_classification_or_ledger_bypass',
        'trust.no_trust_iolta_before_foundation_acceptance',
        'communications.no_sms_whatsapp_without_unrevoked_consent',
        'systems.no_second_license_entitlement_signature_system',
        'entitlements.no_feature_flag_grants_access',
        'legal_specialist.no_inappropriate_legal_language_without_configuration',
        'legal_ai.no_customer_facing_auto_approval_or_filing_implication',
        'ai.no_cross_firm_or_metadata_only_retrieval',
        'platform_roles.no_unrestricted_employee_access_by_default',
        'imports.no_production_write_without_preview_validation_confirmation',
        'templates.no_silent_template_upgrade_or_historical_draft_mutation',
        'deployment.no_code_fork_or_connectivity_required_license_validation',
        'legal_data.no_destructive_cancellation_suspension_or_expiry',
        'dedicated_deal.no_first_deal_before_fleet_and_offline_license_rehearsal',
    ];

    private ProfessionalReviewGateMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProfessionalReviewGateMappingService();
    }

    public function test_all_seventeen_gate_keys_are_explicitly_declared(): void
    {
        $declaredKeys = array_keys($this->service->all());

        $this->assertCount(17, $declaredKeys);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required professional-review gate key: {$key}");
        }
    }

    public function test_no_duplicate_gate_keys_exist(): void
    {
        $keys = array_keys($this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate professional-review gate key(s) found.');
    }

    public function test_every_entry_is_a_governance_mapping_result_with_notes(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertInstanceOf(GovernanceMappingResult::class, $item, "Entry {$key} must be a GovernanceMappingResult.");
            $this->assertSame($key, $item->item_key);
            $this->assertNotEmpty($item->notes, "Item {$key} should have explanatory notes.");
            $this->assertNotEmpty($item->item_label, "Item {$key} should have a label.");
        }
    }

    public function test_byKey_returns_the_matching_entry(): void
    {
        $item = $this->service->byKey('ai.no_cross_firm_or_metadata_only_retrieval');

        $this->assertNotNull($item);
        $this->assertSame('ai.no_cross_firm_or_metadata_only_retrieval', $item->item_key);
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist.key'));
    }

    public function test_passed_partial_failed_and_not_applicable_yet_classify_every_item_exactly_once(): void
    {
        $passed = array_keys($this->service->passed());
        $partial = array_keys($this->service->partial());
        $failed = array_keys($this->service->failed());
        $notApplicable = array_keys($this->service->notApplicableYet());

        $this->assertEmpty(array_intersect($passed, $partial));
        $this->assertEmpty(array_intersect($passed, $failed));
        $this->assertEmpty(array_intersect($passed, $notApplicable));
        $this->assertEmpty(array_intersect($partial, $failed));
        $this->assertEmpty(array_intersect($partial, $notApplicable));
        $this->assertEmpty(array_intersect($failed, $notApplicable));

        $union = array_merge($passed, $partial, $failed, $notApplicable);
        sort($union);

        $allKeys = array_keys($this->service->all());
        sort($allKeys);

        $this->assertSame($allKeys, $union, 'Every gate key must be classified into exactly one bucket.');
    }

    public function test_status_enum_values_match_the_classification_accessors(): void
    {
        foreach ($this->service->passed() as $item) {
            $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        }

        foreach ($this->service->partial() as $item) {
            $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        }

        foreach ($this->service->failed() as $item) {
            $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
        }

        foreach ($this->service->notApplicableYet() as $item) {
            $this->assertSame(GovernanceMappingStatus::NotApplicableYet, $item->status);
        }
    }

    public function test_overall_gate_status_returns_the_required_structured_keys(): void
    {
        $status = $this->service->overallGateStatus();

        foreach ([
            'clear_for_execution', 'passed_count', 'partial_count', 'failed_count',
            'not_applicable_yet_count', 'production_blockers', 'dedicated_private_deal_blockers',
            'referenced_gaps', 'notes',
        ] as $expectedKey) {
            $this->assertArrayHasKey($expectedKey, $status, "overallGateStatus() must include key '{$expectedKey}'.");
        }

        $this->assertIsBool($status['clear_for_execution']);
        $this->assertIsInt($status['passed_count']);
        $this->assertIsInt($status['partial_count']);
        $this->assertIsInt($status['failed_count']);
        $this->assertIsInt($status['not_applicable_yet_count']);
        $this->assertIsArray($status['production_blockers']);
        $this->assertIsArray($status['dedicated_private_deal_blockers']);
        $this->assertIsArray($status['referenced_gaps']);
        $this->assertIsString($status['notes']);
        $this->assertNotEmpty($status['notes']);
    }

    public function test_overall_gate_status_counts_are_consistent_with_the_classification_accessors(): void
    {
        $status = $this->service->overallGateStatus();

        $this->assertSame(count($this->service->passed()), $status['passed_count']);
        $this->assertSame(count($this->service->partial()), $status['partial_count']);
        $this->assertSame(count($this->service->failed()), $status['failed_count']);
        $this->assertSame(count($this->service->notApplicableYet()), $status['not_applicable_yet_count']);
        $this->assertSame($status['failed_count'] === 0, $status['clear_for_execution']);
    }

    public function test_overall_gate_status_is_not_merely_a_boolean(): void
    {
        $status = $this->service->overallGateStatus();

        $this->assertGreaterThan(1, count($status), 'overallGateStatus() must be a structured breakdown, not only a boolean.');
    }

    public function test_gaps_accessor_exists_and_is_an_array(): void
    {
        $this->assertIsArray($this->service->gaps());
    }
}
