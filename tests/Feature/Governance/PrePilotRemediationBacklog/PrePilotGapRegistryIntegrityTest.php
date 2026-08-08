<?php

namespace Tests\Feature\Governance\PrePilotRemediationBacklog;

use App\Services\ComplianceGapRegistryService;
use App\Services\PrePilotRemediationBacklogService;
use Tests\Concerns\EvaluatesHistoricalCheckpointScope;
use Tests\TestCase;

/**
 * PrePilotGapRegistryIntegrityTest — proves Section 38 added NO new
 * gap to ComplianceGapRegistryService and that
 * PrePilotRemediationBacklogService reads/classifies the LIVE
 * registry rather than hardcoding a separate, contradictory register.
 */
class PrePilotGapRegistryIntegrityTest extends TestCase
{
    use EvaluatesHistoricalCheckpointScope;

    private ComplianceGapRegistryService $registry;

    private PrePilotRemediationBacklogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ComplianceGapRegistryService;
        $this->service = new PrePilotRemediationBacklogService;
    }

    public function test_gap_count_remains_twenty_one(): void
    {
        $this->assertCount(21, $this->registry->all());
    }

    public function test_no_gap_key_from_the_registry_is_omitted_from_the_backlog_service(): void
    {
        $registryKeys = array_map(fn ($item) => $item->key, $this->registry->all());
        $backlogKeys = $this->service->gapKeys();

        $this->assertEmpty(array_diff($registryKeys, $backlogKeys), 'Every registry gap key must appear in the backlog service.');
    }

    public function test_no_duplicate_gap_key_in_registry_or_backlog(): void
    {
        $registryKeys = array_map(fn ($item) => $item->key, $this->registry->all());
        $this->assertCount(count($registryKeys), array_unique($registryKeys));

        $backlogKeys = $this->service->gapKeys();
        $this->assertCount(count($backlogKeys), array_unique($backlogKeys));
    }

    public function test_compliance_gap_registry_service_was_not_modified(): void
    {
        $changed = $this->changedOrUntrackedPaths('app/Services/ComplianceGapRegistryService.php');

        $this->assertEmpty($changed, 'ComplianceGapRegistryService.php must not be modified by Section 38 — no new pre-pilot risk was found that is not already tracked.');
    }

    public function test_backlog_service_reads_the_registry_key_set_exactly_rather_than_a_hardcoded_copy(): void
    {
        $registryKeys = array_map(fn ($item) => $item->key, $this->registry->all());
        $backlogKeys = $this->service->gapKeys();

        sort($registryKeys);
        sort($backlogKeys);

        $this->assertSame($registryKeys, $backlogKeys, 'The backlog service key set must exactly match the live registry key set (no contradictory hardcoded register).');
    }

    /**
     * @return array<int, string>
     */
    private function changedOrUntrackedPaths(string $scope): array
    {
        $changed = $this->changedOrUntrackedPathsRaw($scope);

        if ($changed === '') {
            return [];
        }

        return preg_split('/\R/', $changed) ?: [];
    }
}
