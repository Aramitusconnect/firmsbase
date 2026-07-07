<?php

namespace Tests\Feature\Governance\EdgeCaseRiskHandling;

use App\Enums\GovernanceGapSeverity;
use App\Enums\GovernanceMappingStatus;
use App\Services\ComplianceGapRegistryService;
use App\Services\EdgeCaseRiskCatalogMappingService;
use Tests\TestCase;

class EdgeCasePaymentAndEntitlementRiskTest extends TestCase
{
    private EdgeCaseRiskCatalogMappingService $service;
    private ComplianceGapRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EdgeCaseRiskCatalogMappingService();
        $this->registry = new ComplianceGapRegistryService();
    }

    public function test_manual_payment_duplicate_submit_is_backed_by_idempotency_evidence(): void
    {
        $item = $this->service->byKey('manual_payment_duplicate_submit');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('idempotency_key', $item->notes);
        $this->assertStringContainsString('unique', strtolower($item->notes));
    }

    public function test_installment_failure_repeated_missed_is_consent_aware(): void
    {
        $item = $this->service->byKey('installment_failure_repeated_missed');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('ConsentService', $item->notes);
        $this->assertStringContainsString('isGranted', $item->notes);
    }

    public function test_payment_plan_renegotiation_preserves_history_and_pauses_dunning(): void
    {
        $item = $this->service->byKey('payment_plan_renegotiation');

        $this->assertSame(GovernanceMappingStatus::Implemented, $item->status);
        $this->assertStringContainsString('supersedes_payment_plan_id', $item->notes);
        $this->assertStringContainsString('dunning-paused', $item->notes);
    }

    public function test_stripe_disconnected_classification_matches_aws_inspection(): void
    {
        $item = $this->service->byKey('stripe_disconnected');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('IntegrationType::Stripe', $item->notes);
        $this->assertStringContainsString('no stripe-account-status', $item->notes);
    }

    public function test_ai_entitlement_removed_with_pending_jobs_classification_matches_aws_inspection(): void
    {
        $item = $this->service->byKey('ai_entitlement_removed_with_pending_jobs');

        $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
        $this->assertStringContainsString('AiApprovalRequestStatus::Pending', $item->notes);
        $this->assertStringContainsString('do NOT re-check', $item->notes);
    }

    public function test_confirmed_gap_keys_exist_with_approved_severities(): void
    {
        $aiGap = $this->registry->byKey('ai_jobs_not_cancelled_when_entitlement_removed');
        $this->assertNotNull($aiGap);
        $this->assertSame(GovernanceGapSeverity::Medium, $aiGap->severity);

        $stripeGap = $this->registry->byKey('stripe_disconnect_payment_collection_block_not_enforced');
        $this->assertNotNull($stripeGap);
        $this->assertSame(GovernanceGapSeverity::Medium, $stripeGap->severity);
    }

    public function test_edge_case_gaps_accessor_includes_ai_and_stripe_findings(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->gaps());

        $this->assertContains('ai_entitlement_removed_with_pending_jobs', $keys);
        $this->assertContains('stripe_disconnected', $keys);
    }
}
