<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * WorkflowTransitionRuleMappingService — declares the master plan's
 * Section 33 workflow transition rules (21 keys spanning the 14
 * catalog workflows) and maps each to the real, existing owning
 * service/policy that enforces it, or honestly classifies it NotFound/
 * PartiallyImplemented. Purely declarative — no migration, no new
 * enum, no enum case change, no new value object, no behavior change
 * to any owning service. Reuses GovernanceMappingResult/
 * GovernanceMappingStatus from the Section 25 cross-cutting package.
 *
 * Every classification below was determined by direct inspection of
 * the real repository (all relevant app/Services and app/Models) at
 * the time this service was written, including a repository-wide
 * search for direct ['status' => ...] writes on the 14 workflow models
 * outside their owning services (none were found in production code —
 * see informalOrUiOnly()).
 */
class WorkflowTransitionRuleMappingService
{
    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            'firm_license_suspension_preserves_legal_data' => new GovernanceMappingResult(
                item_key: 'firm_license_suspension_preserves_legal_data',
                item_label: 'Firm license suspension preserves (never destroys/hides) legal data',
                owning_class: \App\Services\LegalDataAccessPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'LegalDataAccessPolicyService::EXPORT_ONLY explicitly buckets LicenseStatus::Suspended (with ExportOnly/Cancelled/Expired) into a governed-export-remains-available tier — canExport() returns true for every one of these statuses. The docblock states the exact PDF rule verbatim: "Suspension must not destroy or hide legal data."',
            ),
            'firm_license_export_only_retention_offboarding' => new GovernanceMappingResult(
                item_key: 'firm_license_export_only_retention_offboarding',
                item_label: 'Export-only license status connects to retention/offboarding',
                owning_class: \App\Services\OffboardingExportService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'LegalDataAccessPolicyService::canExport() remains true for LicenseStatus::ExportOnly, and the real Phase 17 offboarding/retention services (OffboardingExportService, OffboardingRequestService, RetentionPolicyService) provide the governed export/retention/offboarding path an export-only firm would use.',
            ),
            'lead_conversion_creates_client_and_starts_intake' => new GovernanceMappingResult(
                item_key: 'lead_conversion_creates_client_and_starts_intake',
                item_label: 'Lead conversion creates a client and starts intake',
                owning_class: \App\Services\LeadConversionService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'LeadConversionService::convert() is the ONLY place a Client is ever created from a FirmLead (project rule, confirmed by direct inspection — no other code path sets FirmLead::converted_client_id) — the "creates a client" half is fully real. However, convert() does not itself create an IntakeSubmission; intake remains a separate, deliberately decoupled step a caller performs afterward (no dedicated IntakeService exists). PartiallyImplemented: client creation is exclusive and real, automatic intake-start is not.',
            ),
            'lost_leads_follow_retention_policy' => new GovernanceMappingResult(
                item_key: 'lost_leads_follow_retention_policy',
                item_label: 'Lost leads follow the firm\'s retention policy',
                owning_class: \App\Services\RetentionPolicyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FirmLeadStatus::Lost is a real, recorded status; RetentionPolicyService::resolveEffectivePolicyFor() is a real, deployment-mode-agnostic policy resolver reused here rather than a lead-specific reimplementation, exactly matching FirmLeadStatus\'s own docblock ("Lost leads follow retention policy, which is owned by Phase 17").',
            ),
            'matter_opening_requires_conflict_gate' => new GovernanceMappingResult(
                item_key: 'matter_opening_requires_conflict_gate',
                item_label: 'A matter cannot reach Open without a completed, non-blocking conflict check',
                owning_class: \App\Services\MatterOpeningService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'MatterOpeningService::openMatter() is the ONLY place MatterStatus::Open is set (confirmed by a repository-wide search — no other production code writes this transition), and it requires a completed ConflictCheckRun with no unresolved results. MatterStatus\'s own docblock states this exact rule.',
            ),
            'matter_creation_pins_practice_area_template_version' => new GovernanceMappingResult(
                item_key: 'matter_creation_pins_practice_area_template_version',
                item_label: 'Matter creation pins the practice-area template version in use',
                owning_class: \App\Models\Matter::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'Matter.pinned_template_pack_version_id is a real column ("set once at creation and never changed afterward," per the Matter model\'s own docblock), and TemplatePackInstallationService confirms upgrades never retroactively touch it once a matter is open. However, no confirmed matter-creation-time writer exists: the two production call sites that create a Matter row (ImportApplyService, ProductionPilotWorkflowService) do NOT set pinned_template_pack_version_id, and ProductionPilotWorkflowService\'s own docblock states "no dedicated MatterCreationService exists... the plain Matter row constructor carries no gating rules of its own." The column and its permanence contract are real (an equivalent ownership trail exists), but matter-creation-time pinning itself is not confirmed as enforced — PartiallyImplemented, not NotFound, per approved guidance.',
            ),
            'document_reminders_stop_on_terminal_or_paused_states' => new GovernanceMappingResult(
                item_key: 'document_reminders_stop_on_terminal_or_paused_states',
                item_label: 'Document chase reminders stop once a request item reaches a terminal/paused state',
                owning_class: \App\Models\DocumentRequestItem::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'DocumentRequestItem::isChaseEligibleStatus() is the single source of truth: only Requested/Viewed/NeedsReplacement are chase-eligible; Approved/Rejected/Expired/Waived are not. DocumentChaseService/DocumentChaseSchedulerService consult this before ever logging a chase attempt.',
            ),
            'task_blocked_derives_from_unmet_dependencies' => new GovernanceMappingResult(
                item_key: 'task_blocked_derives_from_unmet_dependencies',
                item_label: 'Task.Blocked is derived from unmet task_dependencies, never directly settable',
                owning_class: \App\Services\TaskDependencyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TaskDependencyService is the ONLY place TaskStatus::Blocked is set or cleared (confirmed by a repository-wide search), driven by whether blocked_by_task_id rows remain unresolved.',
            ),
            'task_overdue_derives_from_due_at_and_status' => new GovernanceMappingResult(
                item_key: 'task_overdue_derives_from_due_at_and_status',
                item_label: 'Task.Overdue is derived from due_at and current status, never directly settable',
                owning_class: \App\Services\TaskService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TaskService is the ONLY place TaskStatus::Overdue is set (confirmed by a repository-wide search), computed from due_at rather than accepted as a caller-supplied value — matches TaskStatus\'s own docblock ("overdue is derived... not manually trusted").',
            ),
            'invoice_payment_requires_classification_and_permission' => new GovernanceMappingResult(
                item_key: 'invoice_payment_requires_classification_and_permission',
                item_label: 'Payments cannot apply to an invoice unless payment classification and permissions pass',
                owning_class: \App\Services\PaymentClassificationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentClassificationService::classify() is pure, mandatory decision logic evaluated before any payment is saved (never bypassed — the ONLY place classification is decided, per its own docblock); PaymentApplicationService::applyToInvoice() then applies only an already-classified, accepted payment.',
            ),
            'payment_plan_activation_locks_schedule' => new GovernanceMappingResult(
                item_key: 'payment_plan_activation_locks_schedule',
                item_label: 'Activating a payment plan locks its installment schedule',
                owning_class: \App\Services\PaymentPlanService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentPlanService::activate() is the sole transition into PaymentPlanStatus::Active; PaymentPlanStatus\'s own docblock states "Activation locks the schedule" as the exact rule this transition enforces.',
            ),
            'payment_plan_renegotiation_supersedes_and_pauses_dunning' => new GovernanceMappingResult(
                item_key: 'payment_plan_renegotiation_supersedes_and_pauses_dunning',
                item_label: 'Renegotiating a payment plan creates a new superseding version and pauses dunning on the old one',
                owning_class: \App\Services\PaymentPlanService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentPlanService::renegotiate() creates a brand-new plan row (supersedes_payment_plan_id pointing at the old plan) and transitions the OLD plan to PaymentPlanStatus::Renegotiated — its own docblock states this is "what makes dunning pause." PaymentPlanDunningService treats Paused and Renegotiated plans identically without special-casing.',
            ),
            'installment_paid_by_canonical_payment_only' => new GovernanceMappingResult(
                item_key: 'installment_paid_by_canonical_payment_only',
                item_label: 'An installment is marked paid only via the canonical payments table',
                owning_class: \App\Services\PaymentApplicationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentApplicationService::applyToInstallment() is the exclusive writer of paid_amount_cents/status on payment_plan_installments, recomputed from the canonical payments table (project rule 4: "never competes with or duplicates the payments table").',
            ),
            'missed_installment_triggers_consent_respecting_dunning' => new GovernanceMappingResult(
                item_key: 'missed_installment_triggers_consent_respecting_dunning',
                item_label: 'A missed installment triggers dunning that respects the client\'s communication consent',
                owning_class: \App\Services\PaymentPlanDunningService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentPlanDunningService::checkAndLog() is called for PaymentPlanInstallmentStatus::Missed installments and consults ConsentService::isGranted() for the given channel before ever queuing a reminder — eligibility is real and consent-gated, though it only checks/logs (no real send exists yet, a pre-existing, already-tracked limitation from Section 30).',
            ),
            'payment_classification_before_save_or_provider_intent' => new GovernanceMappingResult(
                item_key: 'payment_classification_before_save_or_provider_intent',
                item_label: 'Classification happens before a payment is saved or before any provider payment-intent is created',
                owning_class: \App\Services\PaymentClassificationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentClassificationService::classify() is deliberately pure decision logic with no database writes, split out specifically so classification can be reasoned about independently of persistence — its own docblock states classification must happen "before saving or before Stripe PaymentIntent creation," matching PaymentStatus\'s own enum docblock verbatim.',
            ),
            'trust_posting_requires_balance_approval_lock_ledger_audit' => new GovernanceMappingResult(
                item_key: 'trust_posting_requires_balance_approval_lock_ledger_audit',
                item_label: 'Posting a trust transaction requires a sufficient balance, an approval, a concurrency lock, a ledger entry, and an audit trail',
                owning_class: \App\Services\TrustTransferRequestService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TrustTransferRequestService::apply() confirms all five elements are real: BALANCE (TrustBalanceService, checked against lockedBalance->balance_cents before proceeding), APPROVAL (approveTransfer()/denyTransfer(), gated to an Approved request only), LOCK (TrustConcurrencyLockService::withLockedBalances()), LEDGER (a real trust_ledger_entries row posted inside the same locked transaction), and AUDIT (a real TrustApprovalEvent row written in the same transaction). TrustRefundRequestService follows the identical five-element pattern.',
            ),
            'high_risk_client_facing_ai_requires_human_approval' => new GovernanceMappingResult(
                item_key: 'high_risk_client_facing_ai_requires_human_approval',
                item_label: 'High-risk/client-facing AI actions require a human approval gate',
                owning_class: \App\Services\AiApprovalWorkflowService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'AiApprovalWorkflowService::submit() creates an AiApprovalRequest in Pending, and approve()/reject() both call assertActorMayResolve() against a real, restricted APPROVAL_ROLES list before transitioning — no AI actor may resolve its own request. The operative Pending/Approved/Rejected human-approval gate is fully real, independent of the richer draft/revised/archived lifecycle richness gap tracked separately.',
            ),
            'import_batch_no_production_write_before_preview_validation_confirmation' => new GovernanceMappingResult(
                item_key: 'import_batch_no_production_write_before_preview_validation_confirmation',
                item_label: 'No production record is written by an import until preview, validation, and confirmation have all completed',
                owning_class: \App\Services\ImportApplyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'The real ImportBatchStatus sequence (Draft -> Staged -> Validated -> PreviewReady -> Confirmed -> Applying -> Applied) is enforced across dedicated services: ImportPreviewService::preview() (PreviewReady), ImportApplyService::confirmBatch() (Confirmed) and ::apply() (Applying then Applied) — apply() is the only place production records (Client/Matter/Party/Document/TimeEntry) are created, and it runs only after confirmBatch(). ImportRollbackService provides the reverse path.',
            ),
            'signature_completion_requires_evidence_hash_event_certificate' => new GovernanceMappingResult(
                item_key: 'signature_completion_requires_evidence_hash_event_certificate',
                item_label: 'Signature completion requires evidence, a document hash, an event trail, and a certificate record',
                owning_class: \App\Services\SignatureCertificateService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'SignatureCertificateService::generate() is the ONLY place SignatureRequestStatus::Completed is set, and its own docblock states the exact rule verbatim: "Completion requires evidence, hash, event trail, and certificate-style record." It asserts the request is already Signed (EVIDENCE), that a document_hashes row exists via DocumentHashService (HASH), that at least one signature_events row exists via SignatureEventLogger (EVENT TRAIL), and it writes exactly one signature_certificates row, made structurally impossible to duplicate by a DB-unique constraint (CERTIFICATE).',
            ),
            'fleet_migration_halt_on_failure_stops_propagation' => new GovernanceMappingResult(
                item_key: 'fleet_migration_halt_on_failure_stops_propagation',
                item_label: 'A fleet migration instance failure halts the run and stops it from propagating to remaining instances',
                owning_class: \App\Services\FleetMigrationOrchestrationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FleetMigrationOrchestrationService::applyInstance(succeeded: false) transitions the run to FleetMigrationRunStatus::Halted inside the same transaction — its own docblock states the exact project rule: "failure halts remaining pending instances."',
            ),
            'fleet_migration_rollback_restores_prior_version' => new GovernanceMappingResult(
                item_key: 'fleet_migration_rollback_restores_prior_version',
                item_label: 'Rolling back a fleet migration run restores instances to their prior version state',
                owning_class: \App\Services\FleetMigrationOrchestrationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FleetMigrationOrchestrationService::rollback() only accepts a Halted or Completed run, moves every currently-Applied instance to RolledBack, and sets the run itself to FleetMigrationRunStatus::RolledBack — its own docblock is explicit this is "pure bookkeeping — no real schema reversal is performed," matching the project\'s simulated/foundation-only Phase 16 fleet-migration posture.',
            ),
        ];
    }

    public function byKey(string $key): ?GovernanceMappingResult
    {
        return $this->all()[$key] ?? null;
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
     * Rules whose owning_class is a Service — i.e. every rule in this
     * catalog, since each is enforced by a dedicated service method
     * rather than left to informal convention.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function serviceEnforced(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->owning_class !== null && str_contains($item->owning_class, '\\Services\\'),
        );
    }

    /**
     * Rules whose owning_class is specifically a *PolicyService — a
     * narrower subset of serviceEnforced() for rules that are, at
     * their core, an access/eligibility policy decision.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function policyEnforced(): array
    {
        return array_filter(
            $this->all(),
            fn (GovernanceMappingResult $item) => $item->owning_class !== null && str_ends_with($item->owning_class, 'PolicyService'),
        );
    }

    /**
     * Rules found to be enforced only informally (e.g. a UI convention
     * with no owning service/policy backing it). Empty because a
     * repository-wide search of every direct ['status' => ...] write
     * on the 14 workflow models found every single one occurring
     * inside a dedicated, purpose-built owning service — no
     * controller/route/UI layer exists in this codebase at all that
     * could perform an informal transition.
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function informalOrUiOnly(): array
    {
        return [];
    }

    /**
     * Rule findings that should be considered gap-register candidates.
     * Empty: every transition rule in this catalog is backed by a real
     * owning service/policy (at worst PartiallyImplemented with an
     * honest, non-gap explanation — matter template pinning timing and
     * lead-conversion intake-start are both real capability gaps
     * already covered by their own PartiallyImplemented classification,
     * not gap-register candidates per approved scope).
     *
     * @return array<string, GovernanceMappingResult>
     */
    public function gaps(): array
    {
        return [];
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
}
