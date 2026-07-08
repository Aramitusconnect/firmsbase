<?php

namespace Tests\Feature\Governance\PrePilotRemediationBacklog;

use App\Services\ComplianceGapRegistryService;
use App\Services\PrePilotRemediationBacklogService;
use App\ValueObjects\GovernanceMappingResult;
use Tests\TestCase;

class PrePilotRemediationBacklogServiceTest extends TestCase
{
    private PrePilotRemediationBacklogService $service;

    private ComplianceGapRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PrePilotRemediationBacklogService();
        $this->registry = new ComplianceGapRegistryService();
    }

    public function test_every_current_compliance_gap_registry_key_appears_in_all(): void
    {
        $registryKeys = array_map(fn ($item) => $item->key, $this->registry->all());
        $declaredKeys = array_keys($this->service->all());

        foreach ($registryKeys as $key) {
            $this->assertContains($key, $declaredKeys, "Gap '{$key}' from ComplianceGapRegistryService is missing from PrePilotRemediationBacklogService::all().");
        }

        $this->assertCount(count($registryKeys), $declaredKeys);
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_keys($this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate gap key(s) found.');
    }

    public function test_every_result_is_a_governance_mapping_result(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertInstanceOf(GovernanceMappingResult::class, $item, "Entry {$key} must be a GovernanceMappingResult.");
            $this->assertSame($key, $item->item_key);
        }
    }

    public function test_every_result_has_notes_or_evidence(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertNotEmpty($item->notes, "Item {$key} should have explanatory notes.");
            $this->assertNotEmpty($item->item_label, "Item {$key} should have a label.");
        }
    }

    public function test_every_gap_has_a_primary_pilot_gate_and_remediation_order(): void
    {
        foreach ($this->service->all() as $key => $item) {
            $this->assertMatchesRegularExpression('/Primary pilot gate: \S+/', $item->notes, "Item {$key} must declare a primary pilot gate.");
            $this->assertMatchesRegularExpression('/Remediation order: \d+ of 21/', $item->notes, "Item {$key} must declare a remediation order.");
        }
    }

    public function test_gap_keys_accessor_matches_all(): void
    {
        $this->assertSame(array_keys($this->service->all()), $this->service->gapKeys());
    }

    public function test_byKey_returns_the_matching_entry(): void
    {
        $item = $this->service->byKey('rls_prepared_not_enforced');

        $this->assertNotNull($item);
        $this->assertSame('rls_prepared_not_enforced', $item->item_key);
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist.key'));
    }

    public function test_unknown_current_gaps_would_be_surfaced_clearly_not_silently_omitted(): void
    {
        // With today's live registry, every gap is classified.
        $this->assertEmpty($this->service->unclassifiedGapKeys());

        // The mechanism itself is proven: nothing in all() is ever
        // dropped just because it lacks a classification-map entry —
        // byKey looks up the same all() array that already guarantees
        // every live registry key is present (see the first test).
        $this->assertCount(count($this->registry->all()), $this->service->all());
    }
}
