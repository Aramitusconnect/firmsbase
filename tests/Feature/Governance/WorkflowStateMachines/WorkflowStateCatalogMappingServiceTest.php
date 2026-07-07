<?php

namespace Tests\Feature\Governance\WorkflowStateMachines;

use App\Enums\GovernanceMappingStatus;
use App\Services\WorkflowStateCatalogMappingService;
use App\ValueObjects\GovernanceMappingResult;
use Tests\TestCase;

class WorkflowStateCatalogMappingServiceTest extends TestCase
{
    private const REQUIRED_KEYS = [
        'firm_license.trial', 'firm_license.active', 'firm_license.past_due', 'firm_license.grace_period',
        'firm_license.read_only', 'firm_license.restricted', 'firm_license.suspended', 'firm_license.cancelled',
        'firm_license.expired', 'firm_license.export_only', 'firm_license.manual', 'firm_license.lifetime',

        'firm_lead.new', 'firm_lead.contacted', 'firm_lead.consultation_scheduled', 'firm_lead.consultation_held',
        'firm_lead.converted', 'firm_lead.lost', 'firm_lead.archived',

        'matter.draft', 'matter.conflict_check_required', 'matter.conflict_review', 'matter.open',
        'matter.active', 'matter.waiting_on_client', 'matter.ready_for_review', 'matter.filed_submitted',
        'matter.closed', 'matter.archived',

        'document_request_item.requested', 'document_request_item.viewed', 'document_request_item.submitted',
        'document_request_item.under_review', 'document_request_item.approved', 'document_request_item.rejected',
        'document_request_item.needs_replacement', 'document_request_item.expired', 'document_request_item.waived',

        'task.open', 'task.in_progress', 'task.blocked', 'task.completed', 'task.cancelled', 'task.overdue',

        'invoice.draft', 'invoice.pending_review', 'invoice.approved', 'invoice.sent', 'invoice.partially_paid',
        'invoice.paid', 'invoice.void', 'invoice.written_off', 'invoice.refunded',

        'payment_plan.draft', 'payment_plan.active', 'payment_plan.paused', 'payment_plan.renegotiated',
        'payment_plan.completed', 'payment_plan.defaulted', 'payment_plan.cancelled',

        'installment.scheduled', 'installment.due', 'installment.paid', 'installment.partially_paid',
        'installment.missed', 'installment.waived', 'installment.cancelled',

        'payment.initiated', 'payment.pending', 'payment.classified', 'payment.blocked', 'payment.succeeded',
        'payment.failed', 'payment.refunded', 'payment.partially_refunded', 'payment.disputed', 'payment.reversed',

        'trust_transfer_refund.draft', 'trust_transfer_refund.pending_review', 'trust_transfer_refund.approved',
        'trust_transfer_refund.rejected', 'trust_transfer_refund.posted', 'trust_transfer_refund.reversed',

        'ai_action.draft', 'ai_action.pending_review', 'ai_action.approved', 'ai_action.rejected',
        'ai_action.revised', 'ai_action.archived',

        'import_batch.uploaded', 'import_batch.mapped', 'import_batch.validated', 'import_batch.previewed',
        'import_batch.confirmed', 'import_batch.processing', 'import_batch.completed',
        'import_batch.completed_with_errors', 'import_batch.rolled_back', 'import_batch.failed',

        'signature_request.draft', 'signature_request.sent', 'signature_request.viewed',
        'signature_request.consented', 'signature_request.signed', 'signature_request.completed',
        'signature_request.declined', 'signature_request.expired', 'signature_request.voided',

        'fleet_migration_run.planned', 'fleet_migration_run.rolling', 'fleet_migration_run.halted',
        'fleet_migration_run.rolled_back', 'fleet_migration_run.completed',
    ];

    private WorkflowStateCatalogMappingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkflowStateCatalogMappingService();
    }

    public function test_all_workflow_state_keys_are_explicitly_declared(): void
    {
        $declaredKeys = array_keys($this->service->all());

        foreach (self::REQUIRED_KEYS as $key) {
            $this->assertContains($key, $declaredKeys, "Missing required workflow state key: {$key}");
        }

        $this->assertCount(113, $declaredKeys);
    }

    public function test_no_duplicate_keys_exist(): void
    {
        $keys = array_keys($this->service->all());

        $this->assertCount(count($keys), array_unique($keys), 'Duplicate workflow state key(s) found.');
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

    public function test_exact_and_cosmetic_matches_are_not_falsely_not_found(): void
    {
        $keys = [
            'firm_license.trial', 'firm_lead.new', 'matter.draft', 'document_request_item.requested',
            'task.open', 'invoice.draft', 'payment_plan.draft', 'installment.scheduled', 'payment.initiated',
            'signature_request.draft', 'fleet_migration_run.planned', 'import_batch.uploaded',
            'trust_transfer_refund.approved', 'ai_action.approved',
        ];

        foreach ($keys as $key) {
            $item = $this->service->byKey($key);

            $this->assertNotNull($item, "Missing catalog key: {$key}");
            $this->assertSame(GovernanceMappingStatus::Implemented, $item->status, "{$key} should be Implemented.");
        }
    }

    public function test_ai_action_draft_revised_archived_are_not_found_because_aws_confirms_three_state_enum(): void
    {
        foreach (['ai_action.draft', 'ai_action.revised', 'ai_action.archived'] as $key) {
            $item = $this->service->byKey($key);

            $this->assertNotNull($item);
            $this->assertSame(GovernanceMappingStatus::NotFound, $item->status, "{$key} should be NotFound.");
        }

        foreach (['ai_action.pending_review', 'ai_action.approved', 'ai_action.rejected'] as $key) {
            $this->assertSame(GovernanceMappingStatus::Implemented, $this->service->byKey($key)->status, "{$key} should be Implemented.");
        }
    }

    public function test_trust_transfer_refund_reversed_is_ledger_layer_partial_because_no_request_level_reversed_exists(): void
    {
        $item = $this->service->byKey('trust_transfer_refund.reversed');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('reverses_entry_id', $item->notes);
    }

    public function test_import_batch_completed_with_errors_is_partial_because_row_level_errors_exist_but_batch_status_lacks_it(): void
    {
        $item = $this->service->byKey('import_batch.completed_with_errors');

        $this->assertSame(GovernanceMappingStatus::PartiallyImplemented, $item->status);
        $this->assertStringContainsString('ImportError', $item->notes);
        $this->assertStringContainsString('ImportRow', $item->notes);
    }

    public function test_workflows_returns_all_fourteen_catalog_workflows(): void
    {
        $this->assertCount(14, $this->service->workflows());
        $this->assertContains('trust_transfer_refund', $this->service->workflows());
        $this->assertContains('fleet_migration_run', $this->service->workflows());
    }

    public function test_byKey_returns_null_for_an_unknown_key(): void
    {
        $this->assertNull($this->service->byKey('does_not_exist.state'));
    }
}
