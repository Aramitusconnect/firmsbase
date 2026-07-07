<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * WorkflowStateCatalogMappingService — declares the master plan's
 * Section 33 workflow state-machine catalog (14 workflows) and maps
 * every representative state to the real, existing enum case/service/
 * derivation evidence found by direct repository inspection, or
 * honestly classifies it NotFound. Purely declarative — no migration,
 * no new enum, no enum case change, no new value object. A cosmetic
 * rename, a derived/computed state, or a state represented at another
 * layer (e.g. a child ledger/event table) all count as real
 * representation — never re-derived as a literal missing case.
 * Reuses GovernanceMappingResult/GovernanceMappingStatus from the
 * Section 25 cross-cutting package.
 *
 * Every classification below was determined by direct inspection of
 * the real repository (all relevant app/Enums and app/Models) at the
 * time this service was written.
 */
class WorkflowStateCatalogMappingService
{
    private const WORKFLOWS = [
        'firm_license', 'firm_lead', 'matter', 'document_request_item', 'task',
        'invoice', 'payment_plan', 'installment', 'payment', 'trust_transfer_refund',
        'ai_action', 'import_batch', 'signature_request', 'fleet_migration_run',
    ];

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function all(): array
    {
        return array_merge(
            $this->firmLicense(),
            $this->firmLead(),
            $this->matter(),
            $this->documentRequestItem(),
            $this->task(),
            $this->invoice(),
            $this->paymentPlan(),
            $this->installment(),
            $this->payment(),
            $this->trustTransferRefund(),
            $this->aiAction(),
            $this->importBatch(),
            $this->signatureRequest(),
            $this->fleetMigrationRun(),
        );
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function workflow(string $workflow): array
    {
        return array_filter(
            $this->all(),
            fn (string $key) => str_starts_with($key, "{$workflow}."),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<int, string>
     */
    public function workflows(): array
    {
        return self::WORKFLOWS;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function implemented(): array
    {
        return $this->byStatus(GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function partial(): array
    {
        return $this->byStatus(GovernanceMappingStatus::PartiallyImplemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function notFound(): array
    {
        return $this->byStatus(GovernanceMappingStatus::NotFound);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function workflowCoverage(): array
    {
        $coverage = [];

        foreach (self::WORKFLOWS as $workflow) {
            $states = $this->workflow($workflow);
            $statuses = array_map(fn (GovernanceMappingResult $s) => $s->status, $states);

            $allImplemented = ! in_array(GovernanceMappingStatus::NotFound, $statuses, true)
                && ! in_array(GovernanceMappingStatus::PartiallyImplemented, $statuses, true);

            $status = $allImplemented ? GovernanceMappingStatus::Implemented : GovernanceMappingStatus::PartiallyImplemented;

            $coverage[$workflow] = new GovernanceMappingResult(
                item_key: $workflow,
                item_label: "{$workflow} workflow-level coverage",
                owning_class: null,
                status: $status,
                notes: sprintf(
                    '%d/%d catalog states Implemented for %s.',
                    count(array_filter($statuses, fn ($s) => $s === GovernanceMappingStatus::Implemented)),
                    count($statuses),
                    $workflow,
                ),
            );
        }

        return $coverage;
    }

    /**
     * States represented as computed/derived (never directly settable
     * by a caller) or shifted to another layer entirely (a child
     * ledger/event table rather than the request-level enum itself).
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function derivedStates(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item, string $key) => in_array($key, [
                'task.blocked',
                'task.overdue',
                'trust_transfer_refund.reversed',
                'import_batch.completed_with_errors',
            ], true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * State findings that should be considered gap-register candidates.
     * Empty unless AWS confirms the AI approval lifecycle is genuinely
     * incomplete (Draft/Revised/Archived absent from AiApprovalRequestStatus).
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function gaps(): array
    {
        $findings = [];

        foreach (['ai_action.draft', 'ai_action.revised', 'ai_action.archived'] as $key) {
            $item = $this->byKey($key);

            if ($item && $item->status === GovernanceMappingStatus::NotFound) {
                $findings[] = $item;
            }
        }

        return $findings;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function byStatus(GovernanceMappingStatus $status): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->status === $status,
        );
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function firmLicense(): array
    {
        $owning = \App\Enums\LicenseStatus::class;
        $fields = [
            'trial' => 'Exact enum case LicenseStatus::Trial.',
            'active' => 'Exact enum case LicenseStatus::Active.',
            'past_due' => 'Exact enum case LicenseStatus::PastDue.',
            'grace_period' => 'Exact enum case LicenseStatus::GracePeriod.',
            'read_only' => 'Exact enum case LicenseStatus::ReadOnly.',
            'restricted' => 'Exact enum case LicenseStatus::Restricted.',
            'suspended' => 'Exact enum case LicenseStatus::Suspended.',
            'cancelled' => 'Exact enum case LicenseStatus::Cancelled.',
            'expired' => 'Exact enum case LicenseStatus::Expired.',
            'export_only' => 'Exact enum case LicenseStatus::ExportOnly.',
            'manual' => 'Exact enum case LicenseStatus::Manual.',
            'lifetime' => 'Exact enum case LicenseStatus::Lifetime.',
        ];

        return $this->buildWorkflow('firm_license', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function firmLead(): array
    {
        $owning = \App\Enums\FirmLeadStatus::class;
        $fields = [
            'new' => 'Exact enum case FirmLeadStatus::New.',
            'contacted' => 'Exact enum case FirmLeadStatus::Contacted.',
            'consultation_scheduled' => 'Exact enum case FirmLeadStatus::ConsultationScheduled.',
            'consultation_held' => 'Exact enum case FirmLeadStatus::ConsultationHeld.',
            'converted' => 'Exact enum case FirmLeadStatus::Converted — the only state LeadConversionService may write, and the only path that may create a Client.',
            'lost' => 'Exact enum case FirmLeadStatus::Lost.',
            'archived' => 'Exact enum case FirmLeadStatus::Archived.',
        ];

        return $this->buildWorkflow('firm_lead', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function matter(): array
    {
        $owning = \App\Enums\MatterStatus::class;
        $fields = [
            'draft' => 'Exact enum case MatterStatus::Draft.',
            'conflict_check_required' => 'Exact enum case MatterStatus::ConflictCheckRequired, set by MatterOpeningService::requestConflictCheck().',
            'conflict_review' => 'Exact enum case MatterStatus::ConflictReview.',
            'open' => 'Exact enum case MatterStatus::Open — reachable ONLY via MatterOpeningService::openMatter() after a completed, non-blocking conflict check.',
            'active' => 'Exact enum case MatterStatus::Active.',
            'waiting_on_client' => 'Exact enum case MatterStatus::WaitingOnClient.',
            'ready_for_review' => 'Exact enum case MatterStatus::ReadyForReview.',
            'filed_submitted' => 'Exact enum case MatterStatus::FiledSubmitted — represents the catalog\'s "filed/submitted where applicable" row; not every practice area reaches it, by design.',
            'closed' => 'Exact enum case MatterStatus::Closed.',
            'archived' => 'Exact enum case MatterStatus::Archived.',
        ];

        return $this->buildWorkflow('matter', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function documentRequestItem(): array
    {
        $owning = \App\Enums\DocumentRequestItemStatus::class;
        $fields = [
            'requested' => 'Exact enum case DocumentRequestItemStatus::Requested — chase-eligible per DocumentRequestItem::isChaseEligibleStatus().',
            'viewed' => 'Exact enum case DocumentRequestItemStatus::Viewed — chase-eligible per DocumentRequestItem::isChaseEligibleStatus().',
            'submitted' => 'Exact enum case DocumentRequestItemStatus::Submitted.',
            'under_review' => 'Exact enum case DocumentRequestItemStatus::UnderReview.',
            'approved' => 'Exact enum case DocumentRequestItemStatus::Approved — chase-ineligible (terminal).',
            'rejected' => 'Exact enum case DocumentRequestItemStatus::Rejected.',
            'needs_replacement' => 'Exact enum case DocumentRequestItemStatus::NeedsReplacement — chase-eligible per DocumentRequestItem::isChaseEligibleStatus().',
            'expired' => 'Exact enum case DocumentRequestItemStatus::Expired — chase-ineligible (terminal).',
            'waived' => 'Exact enum case DocumentRequestItemStatus::Waived — chase-ineligible (terminal).',
        ];

        return $this->buildWorkflow('document_request_item', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function task(): array
    {
        $owning = \App\Enums\TaskStatus::class;

        $result = $this->buildWorkflow('task', $owning, [
            'open' => 'Exact enum case TaskStatus::Open.',
            'in_progress' => 'Exact enum case TaskStatus::InProgress.',
            'completed' => 'Exact enum case TaskStatus::Completed.',
            'cancelled' => 'Exact enum case TaskStatus::Cancelled.',
        ], GovernanceMappingStatus::Implemented);

        $result['task.blocked'] = $this->result(
            'task',
            'blocked',
            \App\Services\TaskDependencyService::class,
            GovernanceMappingStatus::Implemented,
            'Real enum case TaskStatus::Blocked, but never directly settable — TaskDependencyService is the ONLY place it is set/cleared, derived from unmet task_dependencies rows. Implemented-by-design: the state is real, its writer is a single dedicated service, not a caller-supplied value.',
        );
        $result['task.overdue'] = $this->result(
            'task',
            'overdue',
            \App\Services\TaskService::class,
            GovernanceMappingStatus::Implemented,
            'Real enum case TaskStatus::Overdue, but never directly settable — TaskService derives it from due_at and current status rather than accepting it as a caller-supplied value. Implemented-by-design: the state is real and correctly derived, not manually trusted.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function invoice(): array
    {
        $owning = \App\Services\InvoiceDraftingService::class;
        $fields = [
            'draft' => 'Exact enum case InvoiceStatus::Draft.',
            'pending_review' => 'Exact enum case InvoiceStatus::PendingReview.',
            'approved' => 'Exact enum case InvoiceStatus::Approved.',
            'sent' => 'Exact enum case InvoiceStatus::Sent.',
            'partially_paid' => 'Exact enum case InvoiceStatus::PartiallyPaid.',
            'paid' => 'Exact enum case InvoiceStatus::Paid.',
            'void' => 'Exact enum case InvoiceStatus::Void.',
            'written_off' => 'Exact enum case InvoiceStatus::WrittenOff.',
            'refunded' => 'Exact enum case InvoiceStatus::Refunded.',
        ];

        return $this->buildWorkflow('invoice', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function paymentPlan(): array
    {
        $owning = \App\Services\PaymentPlanService::class;
        $fields = [
            'draft' => 'Exact enum case PaymentPlanStatus::Draft.',
            'active' => 'Exact enum case PaymentPlanStatus::Active — PaymentPlanService::activate() locks the installment schedule at this transition.',
            'paused' => 'Exact enum case PaymentPlanStatus::Paused — a plan in this state is skipped by dunning eligibility, per PaymentPlanDunningService.',
            'renegotiated' => 'Exact enum case PaymentPlanStatus::Renegotiated — set on the OLD plan by PaymentPlanService::renegotiate(), which is what pauses its dunning and creates a new superseding plan row.',
            'completed' => 'Exact enum case PaymentPlanStatus::Completed.',
            'defaulted' => 'Exact enum case PaymentPlanStatus::Defaulted.',
            'cancelled' => 'Exact enum case PaymentPlanStatus::Cancelled.',
        ];

        return $this->buildWorkflow('payment_plan', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function installment(): array
    {
        $owning = \App\Enums\PaymentPlanInstallmentStatus::class;
        $fields = [
            'scheduled' => 'Exact enum case PaymentPlanInstallmentStatus::Scheduled.',
            'due' => 'Exact enum case PaymentPlanInstallmentStatus::Due.',
            'paid' => 'Exact enum case PaymentPlanInstallmentStatus::Paid — set exclusively by PaymentApplicationService from the canonical payments table.',
            'partially_paid' => 'Exact enum case PaymentPlanInstallmentStatus::PartiallyPaid.',
            'missed' => 'Exact enum case PaymentPlanInstallmentStatus::Missed — triggers PaymentPlanDunningService::checkAndLog(), which is consent-gated via ConsentService.',
            'waived' => 'Exact enum case PaymentPlanInstallmentStatus::Waived.',
            'cancelled' => 'Exact enum case PaymentPlanInstallmentStatus::Cancelled.',
        ];

        return $this->buildWorkflow('installment', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function payment(): array
    {
        $owning = \App\Services\PaymentClassificationService::class;
        $fields = [
            'initiated' => 'Exact enum case PaymentStatus::Initiated.',
            'pending' => 'Exact enum case PaymentStatus::Pending.',
            'classified' => 'Exact enum case PaymentStatus::Classified — PaymentClassificationService::classify() is pure decision logic, called before any save.',
            'blocked' => 'Exact enum case PaymentStatus::Blocked — a blocked row can never transition to Succeeded (enforced by project rule, reflected in PaymentClassificationService).',
            'succeeded' => 'Exact enum case PaymentStatus::Succeeded.',
            'failed' => 'Exact enum case PaymentStatus::Failed.',
            'refunded' => 'Exact enum case PaymentStatus::Refunded.',
            'partially_refunded' => 'Exact enum case PaymentStatus::PartiallyRefunded.',
            'disputed' => 'Exact enum case PaymentStatus::Disputed.',
            'reversed' => 'Exact enum case PaymentStatus::Reversed.',
        ];

        return $this->buildWorkflow('payment', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function trustTransferRefund(): array
    {
        $result = [];

        $result['trust_transfer_refund.draft'] = $this->result(
            'trust_transfer_refund',
            'draft',
            \App\Enums\TrustTransferRequestStatus::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No literal "draft" case on TrustTransferRequestStatus or TrustRefundRequestStatus — both are created directly in Requested, with no separate editable-draft phase. TrustTransferRequestStatus::Requested/TrustRefundRequestStatus::Requested is the closest real initial state, representing "submitted" rather than "not yet submitted."',
        );
        $result['trust_transfer_refund.pending_review'] = $this->result(
            'trust_transfer_refund',
            'pending_review',
            \App\Enums\TrustTransferRequestStatus::class,
            GovernanceMappingStatus::Implemented,
            'Cosmetic rename: TrustTransferRequestStatus::PendingApproval / TrustRefundRequestStatus::PendingApproval are the real enum cases representing this state.',
        );
        $result['trust_transfer_refund.approved'] = $this->result(
            'trust_transfer_refund',
            'approved',
            \App\Enums\TrustTransferRequestStatus::class,
            GovernanceMappingStatus::Implemented,
            'Exact enum case: TrustTransferRequestStatus::Approved / TrustRefundRequestStatus::Approved, set by TrustTransferRequestService::approveTransfer()/TrustRefundRequestService.',
        );
        $result['trust_transfer_refund.rejected'] = $this->result(
            'trust_transfer_refund',
            'rejected',
            \App\Enums\TrustTransferRequestStatus::class,
            GovernanceMappingStatus::Implemented,
            'Cosmetic rename: TrustTransferRequestStatus::Denied / TrustRefundRequestStatus::Denied are the real enum cases representing this state.',
        );
        $result['trust_transfer_refund.posted'] = $this->result(
            'trust_transfer_refund',
            'posted',
            \App\Services\TrustTransferRequestService::class,
            GovernanceMappingStatus::Implemented,
            'Cosmetic rename: TrustTransferRequestStatus::Applied (set by TrustTransferRequestService::apply(), which posts the real trust_ledger_entries row) / TrustRefundRequestStatus::Completed are the real terminal "posted" states.',
        );
        $result['trust_transfer_refund.reversed'] = $this->result(
            'trust_transfer_refund',
            'reversed',
            \App\Models\TrustLedgerEntry::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No request-level Reversed case exists on TrustTransferRequestStatus or TrustRefundRequestStatus (confirmed by direct enum inspection). Reversal is represented one layer down instead: TrustLedgerEntryReversalService posts a brand-new trust_ledger_entries row referencing the original via reverses_entry_id — a real, ledger-layer reversal mechanism, just not a request-level status value.',
        );

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function aiAction(): array
    {
        $owning = \App\Enums\AiApprovalRequestStatus::class;

        $result = [];
        $result['ai_action.draft'] = $this->result('ai_action', 'draft', $owning, GovernanceMappingStatus::NotFound, 'AiApprovalRequestStatus has exactly 3 cases: Pending, Approved, Rejected (confirmed by direct enum inspection). No Draft case exists — AiApprovalWorkflowService::submit() creates a request directly in Pending.');
        $result['ai_action.pending_review'] = $this->result('ai_action', 'pending_review', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: AiApprovalRequestStatus::Pending is the real enum case representing this state.');
        $result['ai_action.approved'] = $this->result('ai_action', 'approved', $owning, GovernanceMappingStatus::Implemented, 'Exact enum case AiApprovalRequestStatus::Approved, set by AiApprovalWorkflowService::approve() after assertActorMayResolve().');
        $result['ai_action.rejected'] = $this->result('ai_action', 'rejected', $owning, GovernanceMappingStatus::Implemented, 'Exact enum case AiApprovalRequestStatus::Rejected, set by AiApprovalWorkflowService::reject().');
        $result['ai_action.revised'] = $this->result('ai_action', 'revised', $owning, GovernanceMappingStatus::NotFound, 'No Revised case exists on AiApprovalRequestStatus (confirmed by direct enum inspection) — the richer draft/revised/archived lifecycle from the catalog is not represented; only the operative Pending/Approved/Rejected human-approval gate is built.');
        $result['ai_action.archived'] = $this->result('ai_action', 'archived', $owning, GovernanceMappingStatus::NotFound, 'No Archived case exists on AiApprovalRequestStatus (confirmed by direct enum inspection).');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function importBatch(): array
    {
        $owning = \App\Enums\ImportBatchStatus::class;

        $result = [];
        $result['import_batch.uploaded'] = $this->result('import_batch', 'uploaded', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: ImportBatchStatus::Draft is the real initial enum case set by ImportBatchService when a batch is created.');
        $result['import_batch.mapped'] = $this->result('import_batch', 'mapped', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: ImportBatchStatus::Staged is the real enum case, set by ImportBatchService once rows are staged/mapped.');
        $result['import_batch.validated'] = $this->result('import_batch', 'validated', $owning, GovernanceMappingStatus::Implemented, 'Exact enum case ImportBatchStatus::Validated.');
        $result['import_batch.previewed'] = $this->result('import_batch', 'previewed', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: ImportBatchStatus::PreviewReady is the real enum case, set by ImportPreviewService::preview().');
        $result['import_batch.confirmed'] = $this->result('import_batch', 'confirmed', $owning, GovernanceMappingStatus::Implemented, 'Exact enum case ImportBatchStatus::Confirmed, set by ImportApplyService::confirmBatch().');
        $result['import_batch.processing'] = $this->result('import_batch', 'processing', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: ImportBatchStatus::Applying is the real enum case, set by ImportApplyService::apply() before rows are written.');
        $result['import_batch.completed'] = $this->result('import_batch', 'completed', $owning, GovernanceMappingStatus::Implemented, 'Cosmetic rename: ImportBatchStatus::Applied is the real terminal enum case, set by ImportApplyService::apply() on success.');
        $result['import_batch.completed_with_errors'] = $this->result(
            'import_batch',
            'completed_with_errors',
            \App\Models\ImportError::class,
            GovernanceMappingStatus::PartiallyImplemented,
            'No distinct CompletedWithErrors batch-level case exists on ImportBatchStatus (confirmed by direct enum inspection). Row-level errors ARE real and represented instead: ImportRow.status tracks each row individually and ImportError (field/severity/message, firm-scoped via import_row_id) records per-row validation failures — a batch can finish Applied while individual rows/errors reveal partial failure at the row layer.',
        );
        $result['import_batch.rolled_back'] = $this->result('import_batch', 'rolled_back', $owning, GovernanceMappingStatus::Implemented, 'Exact enum case ImportBatchStatus::RolledBack, set by ImportRollbackService::rollbackBatch().');
        $result['import_batch.failed'] = $this->result('import_batch', 'failed', $owning, GovernanceMappingStatus::Implemented, 'Exact enum case ImportBatchStatus::Failed.');

        return $result;
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function signatureRequest(): array
    {
        $owning = \App\Enums\SignatureRequestStatus::class;
        $fields = [
            'draft' => 'Exact enum case SignatureRequestStatus::Draft.',
            'sent' => 'Exact enum case SignatureRequestStatus::Sent.',
            'viewed' => 'Exact enum case SignatureRequestStatus::Viewed.',
            'consented' => 'Exact enum case SignatureRequestStatus::Consented.',
            'signed' => 'Exact enum case SignatureRequestStatus::Signed.',
            'completed' => 'Exact enum case SignatureRequestStatus::Completed — set only by SignatureCertificateService::generate() after evidence/hash/event-trail preconditions are all satisfied.',
            'declined' => 'Exact enum case SignatureRequestStatus::Declined.',
            'expired' => 'Exact enum case SignatureRequestStatus::Expired.',
            'voided' => 'Exact enum case SignatureRequestStatus::Voided.',
        ];

        return $this->buildWorkflow('signature_request', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @return array<string, GovernanceMappingResult>
     */
    private function fleetMigrationRun(): array
    {
        $owning = \App\Services\FleetMigrationOrchestrationService::class;
        $fields = [
            'planned' => 'Cosmetic rename: FleetMigrationRunStatus::Pending is the real enum case, set by FleetMigrationOrchestrationService::createRun().',
            'rolling' => 'Cosmetic rename: FleetMigrationRunStatus::InProgress is the real enum case, set by FleetMigrationOrchestrationService::begin().',
            'halted' => 'Exact enum case FleetMigrationRunStatus::Halted — set automatically by applyInstance() on any instance failure (halt-on-failure, enforced in code).',
            'rolled_back' => 'Exact enum case FleetMigrationRunStatus::RolledBack — only reachable from Halted or Completed, via FleetMigrationOrchestrationService::rollback().',
            'completed' => 'Exact enum case FleetMigrationRunStatus::Completed.',
        ];

        return $this->buildWorkflow('fleet_migration_run', $owning, $fields, GovernanceMappingStatus::Implemented);
    }

    /**
     * @param  array<string, string>  $fields
     * @return array<string, GovernanceMappingResult>
     */
    private function buildWorkflow(string $workflow, ?string $owningClass, array $fields, GovernanceMappingStatus $status): array
    {
        $result = [];

        foreach ($fields as $field => $note) {
            $result["{$workflow}.{$field}"] = $this->result($workflow, $field, $owningClass, $status, $note);
        }

        return $result;
    }

    private function result(string $workflow, string $state, ?string $owningClass, GovernanceMappingStatus $status, string $notes): GovernanceMappingResult
    {
        return new GovernanceMappingResult(
            item_key: "{$workflow}.{$state}",
            item_label: "{$workflow}.{$state}",
            owning_class: $owningClass,
            status: $status,
            notes: $notes,
        );
    }
}
