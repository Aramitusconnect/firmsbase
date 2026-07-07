<?php

namespace Tests\Feature\Governance\EntityFieldCatalog;

use App\Enums\GovernanceGapSeverity;
use App\Enums\GovernanceMappingStatus;
use App\Services\ComplianceGapRegistryService;
use App\Services\EntityFieldCatalogMappingService;
use Tests\TestCase;

/**
 * EntityFieldCatalogTrustAccountabilityTest — proves the trust_ledger_
 * entries.posted_by classification matches real AWS evidence, and that
 * the conditional gap-register rule was applied correctly: a gap is
 * registered ONLY because AWS confirmed the Reversal/ChargebackReversal
 * posting path has no guaranteed actor trail (direct or indirect),
 * not merely because a direct posted_by column is absent.
 */
class EntityFieldCatalogTrustAccountabilityTest extends TestCase
{
    private EntityFieldCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntityFieldCatalogMappingService();
    }

    public function test_trust_ledger_entries_posted_by_direct_field_is_not_found(): void
    {
        $item = $this->service->byKey('trust_ledger_entries.posted_by');

        $this->assertNotNull($item);
        $this->assertSame(GovernanceMappingStatus::NotFound, $item->status);
    }

    public function test_notes_document_whether_indirect_actor_attribution_is_guaranteed_or_not(): void
    {
        $item = $this->service->byKey('trust_ledger_entries.posted_by');

        $this->assertStringContainsString('trust_approval_event_id', $item->notes);
        $this->assertStringContainsString('trust_transfer_request_id', $item->notes);
        $this->assertStringContainsString('trust_refund_request_id', $item->notes);
        $this->assertStringContainsString('Reversal', $item->notes);
        $this->assertStringContainsString('guaranteed indirectly', $item->notes);
        $this->assertStringContainsStringIgnoringCase('no guaranteed actor trail', $item->notes);
    }

    public function test_gap_candidate_exists_because_actor_trail_is_not_guaranteed_for_every_path(): void
    {
        $gapKeys = array_map(fn ($g) => $g->item_key, $this->service->gaps());

        $this->assertContains('trust_ledger_entries.posted_by', $gapKeys);
        $this->assertCount(1, $gapKeys, 'Exactly one gap-register candidate is expected from this catalog.');
    }

    public function test_compliance_gap_registry_service_includes_the_confirmed_trust_ledger_gap(): void
    {
        $registry = new ComplianceGapRegistryService();
        $item = $registry->byKey('trust_ledger_entry_posting_actor_not_guaranteed');

        $this->assertNotNull($item, 'ComplianceGapRegistryService must include trust_ledger_entry_posting_actor_not_guaranteed since AWS confirmed the Reversal/ChargebackReversal path has no guaranteed actor trail.');
        $this->assertSame(GovernanceGapSeverity::High, $item->severity);
    }

    public function test_gap_notes_explain_reversal_path_has_no_guaranteed_actor_trail(): void
    {
        $registry = new ComplianceGapRegistryService();
        $item = $registry->byKey('trust_ledger_entry_posting_actor_not_guaranteed');

        $this->assertStringContainsString('Reversal', $item->description);
        $this->assertStringContainsString('TrustChargebackEvent', $item->description);
    }

    public function test_no_duplicate_trust_ledger_gap_keys_exist(): void
    {
        $registry = new ComplianceGapRegistryService();
        $trustGapKeys = array_filter(
            array_map(fn ($g) => $g->key, $registry->all()),
            fn (string $key) => str_contains($key, 'trust_ledger'),
        );

        $this->assertCount(1, $trustGapKeys);
    }
}
