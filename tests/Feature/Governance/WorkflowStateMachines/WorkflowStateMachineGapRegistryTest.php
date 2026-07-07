<?php

namespace Tests\Feature\Governance\WorkflowStateMachines;

use App\Enums\GovernanceGapSeverity;
use App\Services\ComplianceGapRegistryService;
use Tests\TestCase;

/**
 * WorkflowStateMachineGapRegistryTest — proves Section 33 added exactly
 * the one AWS-confirmed gap to the EXISTING ComplianceGapRegistryService
 * (16 -> 17), because AiApprovalRequestStatus was confirmed to still
 * only have Pending/Approved/Rejected — and did NOT add gaps for
 * cosmetic enum naming differences, trust request-level Reversed, or
 * import completed_with_errors.
 */
class WorkflowStateMachineGapRegistryTest extends TestCase
{
    private ComplianceGapRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ComplianceGapRegistryService();
    }

    public function test_section_32_gap_count_before_section_33_additions_was_sixteen(): void
    {
        // 16 pre-existing (Section 25-32) + 1 new Section 33
        // AI-approval-lifecycle gap (confirmed) = 17.
        $this->assertCount(17, $this->service->all());
    }

    public function test_ai_approval_lifecycle_gap_exists_because_aws_confirmed_three_state_enum(): void
    {
        $item = $this->service->byKey('ai_approval_request_lifecycle_states_incomplete');

        $this->assertNotNull($item, 'ai_approval_request_lifecycle_states_incomplete must exist since AiApprovalRequestStatus was confirmed to only have Pending/Approved/Rejected.');
        $this->assertSame(GovernanceGapSeverity::Low, $item->severity);
    }

    public function test_final_gap_count_is_seventeen(): void
    {
        $this->assertCount(17, $this->service->all());
    }

    public function test_no_gaps_were_added_for_cosmetic_enum_names_trust_request_reversed_or_import_completed_with_errors(): void
    {
        $forbiddenGapKeys = [
            'trust_transfer_request_reversed_missing',
            'trust_refund_request_reversed_missing',
            'import_batch_completed_with_errors_missing',
            'fleet_migration_run_naming_mismatch',
            'firm_license_naming_mismatch',
        ];

        foreach ($forbiddenGapKeys as $key) {
            $this->assertFalse($this->service->isTracked($key), "Gap '{$key}' must not exist — Section 33 does not add gaps for cosmetic naming differences or layer-shifted representations.");
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
