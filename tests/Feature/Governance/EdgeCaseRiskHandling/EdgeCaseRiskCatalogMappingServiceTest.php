<?php

namespace Tests\Feature\Governance\EdgeCaseRiskHandling;

use App\Enums\GovernanceMappingStatus;
use App\Services\EdgeCaseRiskCatalogMappingService;
use App\ValueObjects\GovernanceMappingResult;
use Tests\TestCase;

class EdgeCaseRiskCatalogMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'downgrade_seat_overuse', 'seat_pool_exhausted_on_invite', 'storage_limit_after_downgrade',
        'ai_entitlement_removed_with_pending_jobs', 'subscription_payment_failed',
        'installment_failure_repeated_missed', 'payment_plan_renegotiation', 'stripe_disconnected',
        'manual_payment_duplicate_submit', 'conflict_false_positive_common_name',
        'organization_conflict_scope_adverse_parties', 'client_wrong_or_duplicate_upload',
        'client_language_template_missing', 'consent_revoked_mid_chase', 'import_bad_data',
        'template_upgrade_active_matters', 'form_edition_retired', 'trust_overdraft_concurrent_requests',
        'prompt_injection_uploaded_pdf', 'fleet_migration_failure_mid_rollout',
        'offline_license_expiry_air_gapped', 'support_emergency_without_firm_approval',
        'legal_hold_blocks_delete',
    ];

    private EdgeCaseRiskCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EdgeCaseRiskCatalogMappingService();
    }

    public function test_all_twenty_three_edge_case_keys_are_explicitly_declared(): void
    {
        $declaredKeys = array_keys($this->service->all());

        $this->assertCount(23, $declaredKeys);

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required edge-case key: {$key}");
        }
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_keys($this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate edge-case key(s) found.');
    }

    public function test_all_entries_return_governance_mapping_result(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertInstanceOf(GovernanceMappingResult::class, $item, "Entry {$key} must be a GovernanceMappingResult.");
        }
    }

    public function test_every_entry_has_notes_or_evidence(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertNotEmpty($item->notes, "Item {$key} should have explanatory notes.");
        }
    }

    public function test_implemented_edge_cases_identify_an_owning_enforcing_service(): void
    {
        foreach ($this->service->implemented() as $key => $item) {
            $this->assertNotNull($item->owning_class, "Implemented item {$key} should identify an owning class.");
        }
    }

    public function test_partial_or_not_found_edge_cases_identify_missing_behavior_clearly(): void
    {
        foreach (array_merge($this->service->partial(), $this->service->notFound()) as $key => $item) {
            $this->assertNotEmpty($item->notes, "Item {$key} should clearly describe what is missing.");
        }
    }

    public function test_implemented_partial_and_not_found_buckets_partition_all_items(): void
    {
        $implemented = array_keys($this->service->implemented());
        $partial = array_keys($this->service->partial());
        $notFound = array_keys($this->service->notFound());

        $union = array_merge($implemented, $partial, $notFound);

        $this->assertCount(23, array_unique($union));
        $this->assertCount(23, $union, 'Buckets must not overlap.');
    }

    public function test_ai_entitlement_removed_with_pending_jobs_is_not_found(): void
    {
        $item = $this->service->byKey('ai_entitlement_removed_with_pending_jobs');

        $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
    }

    public function test_client_language_template_missing_is_not_found(): void
    {
        $item = $this->service->byKey('client_language_template_missing');

        $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
    }

    public function test_support_emergency_without_firm_approval_is_partially_implemented(): void
    {
        $item = $this->service->byKey('support_emergency_without_firm_approval');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist'));
    }
}
