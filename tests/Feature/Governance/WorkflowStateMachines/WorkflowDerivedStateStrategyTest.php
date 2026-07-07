<?php

namespace Tests\Feature\Governance\WorkflowStateMachines;

use App\Services\WorkflowStateCatalogMappingService;
use Tests\TestCase;

/**
 * WorkflowDerivedStateStrategyTest — proves derived/layer-shifted
 * states are documented as such, never mistaken for a schema/enum-
 * change instruction. task.blocked/task.overdue are computed rather
 * than directly settable; trust_transfer_refund.reversed and
 * import_batch.completed_with_errors are represented one layer down
 * (a ledger entry / row-level error table) rather than as a
 * request-level or batch-level status value.
 */
class WorkflowDerivedStateStrategyTest extends TestCase
{
    private WorkflowStateCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowStateCatalogMappingService();
    }

    public function test_task_blocked_and_overdue_are_documented_as_derived(): void
    {
        $blocked = $this->service->byKey('task.blocked');
        $overdue = $this->service->byKey('task.overdue');

        $this->assertNotNull($blocked);
        $this->assertNotNull($overdue);

        $this->assertStringContainsString('unmet', $blocked->notes);
        $this->assertStringContainsStringIgnoringCase('never directly settable', $blocked->notes);
        $this->assertStringContainsStringIgnoringCase('derives', $overdue->notes);
        $this->assertStringContainsStringIgnoringCase('due_at', $overdue->notes);
    }

    public function test_trust_transfer_refund_reversed_is_documented_as_ledger_entry_layer_reversal(): void
    {
        $item = $this->service->byKey('trust_transfer_refund.reversed');

        $this->assertNotNull($item);
        $this->assertStringContainsString('trust_ledger_entries', $item->notes);
        $this->assertStringContainsString('reverses_entry_id', $item->notes);
        $this->assertSame(\App\Models\TrustLedgerEntry::class, $item->owning_class);
    }

    public function test_import_batch_completed_with_errors_is_documented_as_row_level_error_representation(): void
    {
        $item = $this->service->byKey('import_batch.completed_with_errors');

        $this->assertNotNull($item);
        $this->assertStringContainsString('row', strtolower($item->notes));
        $this->assertSame(\App\Models\ImportError::class, $item->owning_class);
    }

    public function test_derived_states_accessor_returns_exactly_the_four_expected_findings(): void
    {
        $keys = array_map(fn ($item) => $item->item_key, $this->service->derivedStates());
        sort($keys);

        $this->assertSame(
            ['import_batch.completed_with_errors', 'task.blocked', 'task.overdue', 'trust_transfer_refund.reversed'],
            $keys,
        );
    }

    public function test_derived_states_are_not_treated_as_schema_or_enum_change_instructions(): void
    {
        foreach ($this->service->derivedStates() as $item) {
            $this->assertStringNotContainsStringIgnoringCase('add a column', $item->notes);
            $this->assertStringNotContainsStringIgnoringCase('add an enum', $item->notes);
            $this->assertStringNotContainsStringIgnoringCase('new migration', $item->notes);
        }
    }
}
