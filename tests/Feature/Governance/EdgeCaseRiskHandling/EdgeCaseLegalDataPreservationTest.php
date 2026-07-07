<?php

namespace Tests\Feature\Governance\EdgeCaseRiskHandling;

use App\Enums\GovernanceMappingStatus;
use App\Services\EdgeCaseRiskCatalogMappingService;
use Tests\TestCase;

/**
 * EdgeCaseLegalDataPreservationTest — uses mapping/service evidence
 * only; no behavior changes are made or exercised here.
 */
class EdgeCaseLegalDataPreservationTest extends TestCase
{
    private EdgeCaseRiskCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EdgeCaseRiskCatalogMappingService();
    }

    public function test_downgrade_seat_overuse_preserves_users_and_legal_data(): void
    {
        $item = $this->service->byKey('downgrade_seat_overuse');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Services\DowngradeEvaluationService::class, $item->owning_class);
        $this->assertStringContainsString('never affects legal data', $item->notes);
        $this->assertStringContainsString('deletes any user', $item->notes);
    }

    public function test_storage_limit_after_downgrade_preserves_documents(): void
    {
        $item = $this->service->byKey('storage_limit_after_downgrade');

        $this->assertContains($item->status, [GovernanceMappingStatus::Implemented, GovernanceMappingStatus::PartiallyImplemented]);
        $this->assertStringContainsStringIgnoringCase('never', $item->notes);
    }

    public function test_subscription_payment_failed_preserves_read_export_access_per_policy(): void
    {
        $item = $this->service->byKey('subscription_payment_failed');

        $this->assertSame(\App\Services\LegalDataAccessPolicyService::class, $item->owning_class);
        $this->assertStringContainsString('read/export access is preserved', $item->notes);
        $this->assertStringContainsString('never abrupt lockout', $item->notes);
    }

    public function test_client_wrong_or_duplicate_upload_does_not_delete_audit_history(): void
    {
        $item = $this->service->byKey('client_wrong_or_duplicate_upload');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertSame(\App\Services\DocumentReplacementService::class, $item->owning_class);
        $this->assertStringContainsString('cannot hard-delete', $item->notes);
    }

    public function test_offline_license_expiry_air_gapped_never_bricks_legal_data_access(): void
    {
        $item = $this->service->byKey('offline_license_expiry_air_gapped');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('never bricked', $item->notes);
    }

    public function test_legal_hold_blocks_delete_blocks_deletion_and_key_destruction(): void
    {
        $item = $this->service->byKey('legal_hold_blocks_delete');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('DeletionGovernanceService', $item->notes);
        $this->assertStringContainsString('KeyDestructionRequestService', $item->notes);
        $this->assertStringContainsString('OffboardingRequestService', $item->notes);
    }

    public function test_legal_data_preservation_accessor_covers_all_expected_findings(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->legalDataPreservation());

        foreach ([
            'downgrade_seat_overuse', 'storage_limit_after_downgrade', 'subscription_payment_failed',
            'installment_failure_repeated_missed', 'client_wrong_or_duplicate_upload',
            'offline_license_expiry_air_gapped', 'legal_hold_blocks_delete',
        ] as $expectedKey) {
            $this->assertContains($expectedKey, $keys);
        }
    }
}
