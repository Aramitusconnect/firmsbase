<?php

namespace Tests\Feature\Governance\AcceptanceTestMatrix;

use App\Enums\GovernanceMappingStatus;
use App\Services\AcceptanceTestMatrixMappingService;
use Tests\TestCase;

class AcceptanceProviderSimulationTest extends TestCase
{
    private AcceptanceTestMatrixMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AcceptanceTestMatrixMappingService();
    }

    public function test_virus_scan_is_stub_simulated_and_references_existing_malware_scanning_gap(): void
    {
        $item = $this->service->byKey('documents.virus_scan');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('real_malware_scanning_engine_stubbed', $item->notes);
        $this->assertStringContainsString('FakeVirusScanner', $item->notes);
    }

    public function test_real_provider_calls_are_not_required_by_any_provider_simulated_entry(): void
    {
        foreach ($this->service->providerSimulated() as $key => $item) {
            $this->assertStringNotContainsStringIgnoringCase('real stripe call', $item->notes);
            $this->assertStringNotContainsStringIgnoringCase('real email sent', $item->notes);
            $this->assertStringNotContainsStringIgnoringCase('real sms sent', $item->notes);
        }
    }

    public function test_simulated_provider_readiness_is_represented_honestly(): void
    {
        $stripeItem = $this->service->byKey('billing.stripe_classification_before_intent');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $stripeItem->status);
        $this->assertStringContainsString('FakeStripeGateway', $stripeItem->notes);
        $this->assertStringContainsString('no real Stripe integration exists yet', $stripeItem->notes);
    }

    public function test_signed_url_requirement_references_existing_signed_url_gap(): void
    {
        $item = $this->service->byKey('documents.signed_urls_via_tenant_context');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('signed_document_url_service_missing', $item->notes);
    }

    public function test_provider_simulated_accessor_returns_the_expected_entries(): void
    {
        $keys = array_keys($this->service->providerSimulated());

        $this->assertContains('documents.virus_scan', $keys);
        $this->assertContains('billing.stripe_classification_before_intent', $keys);
    }
}
