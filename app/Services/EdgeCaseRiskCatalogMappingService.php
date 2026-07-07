<?php

namespace App\Services;

use App\Enums\GovernanceMappingStatus;
use App\ValueObjects\GovernanceMappingResult;

/**
 * EdgeCaseRiskCatalogMappingService — declares the master plan's
 * Section 35 edge-case/risk-handling catalog (23 distinct edge cases —
 * "Payment failed" and "Installment failure" are separate rows, not
 * one) and maps each to the real, existing backend service/enum/
 * policy evidence found by direct repository inspection, or honestly
 * classifies it NotFound. Purely declarative — no migration, no new
 * enum, no new value object, no behavior change to any owning domain
 * service. Reuses GovernanceMappingResult/GovernanceMappingStatus from
 * the Section 25 cross-cutting package.
 *
 * Every classification below was determined by direct inspection of
 * the real repository (all relevant app/Services, app/Models, and
 * app/Enums) at the time this service was written.
 */
class EdgeCaseRiskCatalogMappingService
{
    private const KEYS = [
        'downgrade_seat_overuse', 'seat_pool_exhausted_on_invite', 'storage_limit_after_downgrade',
        'ai_entitlement_removed_with_pending_jobs', 'subscription_payment_failed',
        'installment_failure_repeated_missed', 'payment_plan_renegotiation', 'stripe_disconnected',
        'manual_payment_duplicate_submit', 'conflict_false_positive_common_name',
        'organization_conflict_scope_adverse_parties', 'client_wrong_or_duplicate_upload',
        'client_language_template_missing', 'consent_revoked_mid_chase', 'import_bad_data',
        'template_upgrade_active_matters', 'form_edition_retired', 'trust_overdraft_concurrent_requests',
        'prompt_injection_uploaded_pdf', 'fleet_migration_failure_mid_rollout',
        'offline_license_expiry_air_gapped', 'support_emergency_without_firm_approval',
        'legal_hold_blocks_delete',
    ];

    /**
     * @return array<string, GovernanceMappingResult>
     */
    public function all(): array
    {
        return [
            'downgrade_seat_overuse' => new GovernanceMappingResult(
                item_key: 'downgrade_seat_overuse',
                item_label: 'Downgrading a plan while seats in use exceed the new plan\'s seat limits',
                owning_class: \App\Services\DowngradeEvaluationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'DowngradeEvaluationService::evaluate() is real, read-only, and computed at request time — it compares SeatEnforcementService::usageFor() against the new plan\'s PlanLimitMetric for every seat class and returns DowngradeCheckStatus::BlockedSeatOveruse if any class is over. Its own docblock states the downgrade decision never affects legal data or deletes any user — that is a completely separate, unrelated concern.',
            ),
            'seat_pool_exhausted_on_invite' => new GovernanceMappingResult(
                item_key: 'seat_pool_exhausted_on_invite',
                item_label: 'Inviting a new user when the firm\'s seat pool for that class is exhausted',
                owning_class: \App\Services\SeatEnforcementService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'SeatEnforcementService::canInvite() exists specifically for this: its own docblock quotes the edge case verbatim ("Block the invite with a clear pool-exhausted message") and callers are expected to check it BEFORE creating the new FirmUser row — the pool can never be silently exceeded.',
            ),
            'storage_limit_after_downgrade' => new GovernanceMappingResult(
                item_key: 'storage_limit_after_downgrade',
                item_label: 'A firm exceeding its plan\'s storage limit after a downgrade',
                owning_class: \App\Services\DowngradeEvaluationService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'DowngradeEvaluationService blocks the DOWNGRADE ITSELF (DowngradeCheckStatus::BlockedStorageOveruse) if current usage already exceeds the new plan\'s storage_gb limit — real and enforced. However, no ongoing enforcement was found at upload time: DocumentUploadPolicyService/DocumentSecurityService check only file extension/size, never a firm\'s aggregate storage usage against its plan limit — so if usage grows past the limit through any other path, new uploads are not blocked. Documents themselves are never deleted by any mechanism found (safe by omission), but "block new uploads over limit" is not an ongoing enforcement today.',
            ),
            'ai_entitlement_removed_with_pending_jobs' => new GovernanceMappingResult(
                item_key: 'ai_entitlement_removed_with_pending_jobs',
                item_label: 'AI entitlement/module access removed while AI work is pending',
                owning_class: \App\Services\AiApprovalWorkflowService::class,
                status: GovernanceMappingStatus::NotFound,
                notes: 'No queued/async AI job class exists at all (confirmed by direct search — no ShouldQueue job tied to AI processing); every AI action is evaluated synchronously via AiModeResolutionService\'s entitlement gate at call time, so there is no in-flight background job to leak. However, a real "pending" concept DOES exist: AiApprovalRequestStatus::Pending rows can sit indefinitely awaiting human review, and AiApprovalWorkflowService::approve()/reject() do NOT re-check AiEntitlementPolicyService/AiModeResolutionService before resolving a Pending request (confirmed by direct inspection) — a request submitted while entitled can still be approved and (by whatever future execution path) acted upon after the firm\'s AI entitlement has since been revoked, with no cancellation of the pending request. See the confirmed ai_jobs_not_cancelled_when_entitlement_removed gap.',
            ),
            'subscription_payment_failed' => new GovernanceMappingResult(
                item_key: 'subscription_payment_failed',
                item_label: 'A firm\'s platform subscription payment fails',
                owning_class: \App\Services\LegalDataAccessPolicyService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'The CONSEQUENCE side is solid and real: LicenseStatus::PastDue/GracePeriod/Restricted are all real states, and LegalDataAccessPolicyService guarantees read/export access is preserved for every one of them (never abrupt lockout, never legal-data destruction). However, PlatformSubscriptionStatus (trialing/active/past_due/cancelled/expired) is explicitly documented as "distinct from LicenseStatus" (its own enum docblock), and no confirmed service wires a platform subscription payment failure to automatically transition the firm\'s LicenseStatus — the two lifecycles exist but are not confirmed to be linked end-to-end.',
            ),
            'installment_failure_repeated_missed' => new GovernanceMappingResult(
                item_key: 'installment_failure_repeated_missed',
                item_label: 'A payment plan installment is repeatedly missed',
                owning_class: \App\Services\PaymentPlanDunningService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentPlanInstallmentStatus::Missed is real; PaymentPlanDunningService::checkAndLog() calls ConsentService::isGranted() before ever queuing a reminder (consent-respecting, never bypassed); PaymentPlanService::markDefaulted() requires an actor and a reason string, confirming firm-confirmed (not automatic/silent) defaulting. No legal-data consequence is triggered by any of this — it stays entirely within the billing/payment-plan lifecycle.',
            ),
            'payment_plan_renegotiation' => new GovernanceMappingResult(
                item_key: 'payment_plan_renegotiation',
                item_label: 'A payment plan is renegotiated mid-term',
                owning_class: \App\Services\PaymentPlanService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PaymentPlanService::renegotiate() creates a brand-new plan row (supersedes_payment_plan_id pointing at the prior plan — full history preserved, nothing overwritten) and transitions the OLD plan to PaymentPlanStatus::Renegotiated, which is exactly what causes PaymentPlanDunningService to treat it as dunning-paused (identically to Paused, without special-casing).',
            ),
            'stripe_disconnected' => new GovernanceMappingResult(
                item_key: 'stripe_disconnected',
                item_label: 'The firm/platform\'s Stripe connection becomes disconnected or degraded',
                owning_class: \App\Services\IntegrationDegradationRegistryService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'IntegrationDegradationRegistryService::behaviorFor(IntegrationType::Stripe) is a real, generic degradation-mode declaration. But no stripe-account-status/connection field exists anywhere on any model (confirmed by direct search), no payment-collection service consults the degradation registry before attempting a charge, and no scheduled/automated installment-collection process exists at all (app/Console does not exist — confirmed by direct search, so there is no "auto-collection" job to disable in the first place). No admin/firm alert mechanism for a Stripe disconnect event was found. See the confirmed stripe_disconnect_payment_collection_block_not_enforced gap.',
            ),
            'manual_payment_duplicate_submit' => new GovernanceMappingResult(
                item_key: 'manual_payment_duplicate_submit',
                item_label: 'The same manual payment is submitted twice',
                owning_class: \App\Models\Payment::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'payments.idempotency_key is real, backed by a partial unique database index (firm_id, idempotency_key) as a defense-in-depth backstop against a genuine concurrent race, on top of ManualPaymentService\'s primary check-then-create idempotent-replay logic — direct evidence already confirmed by IdempotencyKeyCoverageMappingService::byKey(\'payment_collection\').',
            ),
            'conflict_false_positive_common_name' => new GovernanceMappingResult(
                item_key: 'conflict_false_positive_common_name',
                item_label: 'A conflict check produces a false-positive match on a common name',
                owning_class: \App\Services\ConflictCheckService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'ConflictCheckResultStatus::PossibleMatch is real, and its own enum docblock quotes the exact edge case: "a common-name match must route to review, never silently block or silently clear." ConflictCheckResult carries reviewed_by/reviewed_at/review_notes (staff notes) and a Dismissed terminal state for the false-positive path.',
            ),
            'organization_conflict_scope_adverse_parties' => new GovernanceMappingResult(
                item_key: 'organization_conflict_scope_adverse_parties',
                item_label: 'An organization-wide conflict search must cover adverse parties across sibling firms',
                owning_class: \App\Services\ConflictCheckService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Organization.conflict_scope (real column, cast to the real ConflictScope enum) drives ConflictCheckService::resolveScope()/siblingFirmIds(), which searches clients/contacts/parties/matter_parties across every sibling firm under the same organization when scope is organization-wide, rather than being silently limited to one firm.',
            ),
            'client_wrong_or_duplicate_upload' => new GovernanceMappingResult(
                item_key: 'client_wrong_or_duplicate_upload',
                item_label: 'A client uploads the wrong document or a duplicate',
                owning_class: \App\Services\DocumentReplacementService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'DocumentReplacementService\'s own docblock states plainly: "Clients cannot hard-delete submitted [documents]." captureCurrentAsVersion()/replaceWith() preserve the original as a DocumentVersion and link the new upload via replaces_document_id — the full history is retained, nothing is destroyed, matching DocumentRequestService::reject()/requestReplacement() for the review-driven correction path.',
            ),
            'client_language_template_missing' => new GovernanceMappingResult(
                item_key: 'client_language_template_missing',
                item_label: 'A client\'s preferred language has no matching translated template',
                owning_class: \App\Models\Client::class,
                status: GovernanceMappingStatus::NotFound,
                notes: 'clients.preferred_language and firm_settings.default_language are both real columns, but no FormTemplate/FormTemplateVersion/DocumentTemplate/DocumentTemplateVersion model has a language column at all (confirmed by direct inspection) — templates are not language-variant in this codebase. No fallback-to-default-language behavior and no staff-notification mechanism for a missing translation was found anywhere. See the confirmed template_language_fallback_staff_notification_missing gap.',
            ),
            'consent_revoked_mid_chase' => new GovernanceMappingResult(
                item_key: 'consent_revoked_mid_chase',
                item_label: 'A client revokes communication consent while a document/payment chase is active',
                owning_class: \App\Services\ConsentService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'ConsentService::revoke() is real and versioned (paired CommunicationConsentEvent row every time). DocumentChaseService::checkAndLog() delegates to NotificationEligibilityService, which reuses the same consent/preference foundation, and PaymentPlanDunningService::checkAndLog() calls ConsentService::isGranted() directly before ever queuing a reminder on any channel — both chase mechanisms are channel-specific and consent-aware, never bypassing a revocation.',
            ),
            'import_bad_data' => new GovernanceMappingResult(
                item_key: 'import_bad_data',
                item_label: 'An import batch contains invalid or bad data',
                owning_class: \App\Services\ImportApplyService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'The real ImportBatchStatus sequence (Draft -> Staged -> Validated -> PreviewReady -> Confirmed -> Applying -> Applied/Failed/RolledBack) is enforced across dedicated services (ImportPreviewService::preview(), ImportApplyService::confirmBatch()/apply()) — apply() is the ONLY place production records are created, and it runs only after confirmBatch(). Row-level bad data is captured by ImportRow.status and ImportError (field/severity/message) rather than silently applied. ImportRollbackService provides the reverse path.',
            ),
            'template_upgrade_active_matters' => new GovernanceMappingResult(
                item_key: 'template_upgrade_active_matters',
                item_label: 'A template pack is upgraded while matters using the prior version are active',
                owning_class: \App\Services\TemplatePackInstallationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'Matter.pinned_template_pack_version_id is documented as "set once at creation and never changed afterward," and TemplatePackInstallationService::install()/markUpgradeAvailable() confirm an upgrade only changes the firm\'s InstalledTemplatePack pointer — it never retroactively touches Matter::pinned_template_pack_version_id on matters that already exist. Applying an upgrade requires an explicit, separate install() call with the newer version; nothing auto-switches an active matter.',
            ),
            'form_edition_retired' => new GovernanceMappingResult(
                item_key: 'form_edition_retired',
                item_label: 'A form edition is retired while drafts/watch items reference it',
                owning_class: \App\Services\FormTemplateService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'FormTemplateService::retire() is deliberately narrow — its own docblock states "the ONLY write this method performs is on $version itself... no form_drafts row is ever read, queried, or updated here," which is exactly the mechanism preserving historical draft references to a retired edition (nothing cascades or breaks). However, no confirmed enforcement blocks a NEW form draft from being created against a Retired-status version, and no "in-review draft remapping" flag or mechanism was found. This is a distinct finding from the already-tracked form_edition_watch_sla_controls_missing gap (that gap concerns the watch-queue SLA/escalation timeline, not retirement-blocking behavior) — not a duplicate.',
            ),
            'trust_overdraft_concurrent_requests' => new GovernanceMappingResult(
                item_key: 'trust_overdraft_concurrent_requests',
                item_label: 'Two concurrent requests attempt to overdraw the same trust balance',
                owning_class: \App\Services\TrustConcurrencyLockService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'TrustConcurrencyLockService::withLockedBalances() wraps every trust-posting operation (TrustTransferRequestService::apply(), TrustRefundRequestService, TrustLedgerEntryReversalService) in a real database lock/transaction, and the balance-sufficiency check (lockedBalance->balance_cents < amountCents) happens INSIDE that lock — a second concurrent request cannot observe a stale, pre-debit balance. This is a distinct concern from the already-tracked trust_ledger_entry_posting_actor_not_guaranteed gap (that gap is about actor attribution on Reversal entries, not concurrency) — not a duplicate.',
            ),
            'prompt_injection_uploaded_pdf' => new GovernanceMappingResult(
                item_key: 'prompt_injection_uploaded_pdf',
                item_label: 'An uploaded document (e.g. a PDF) contains an adversarial prompt-injection instruction',
                owning_class: \App\Services\PromptInjectionResistanceService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'PromptInjectionResistanceService is real, with detectsInjectionAttempt() (deterministic denylist detection) and wrapAsUntrustedData() (explicit untrusted-data marking of document-derived text before it ever reaches an AI provider adapter). ai_tool_actions.was_constrained is set whenever an injection attempt is detected, and AiToolActionStatus::Blocked covers both an entitlement/mode block and a resistance-service rejection. A dedicated tests/Feature/Ai/PromptInjection/ directory provides real test coverage.',
            ),
            'fleet_migration_failure_mid_rollout' => new GovernanceMappingResult(
                item_key: 'fleet_migration_failure_mid_rollout',
                item_label: 'A fleet migration fails partway through a rollout',
                owning_class: \App\Services\FleetMigrationOrchestrationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'FleetMigrationOrchestrationService::applyInstance(succeeded: false) automatically transitions the entire run to FleetMigrationRunStatus::Halted (halt-on-failure, enforced in code, per its own docblock: "failure halts remaining pending instances"), and rollback() (restricted to Halted/Completed runs) moves every Applied instance back to RolledBack. VersionSkewPolicyService provides the real skew-check backing a version-skew dashboard/mapping.',
            ),
            'offline_license_expiry_air_gapped' => new GovernanceMappingResult(
                item_key: 'offline_license_expiry_air_gapped',
                item_label: 'An air-gapped/offline dedicated instance\'s license file expires',
                owning_class: \App\Services\LicenseFileValidationService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'LicenseFileValidationService moves an expired license through GracePeriod then Restricted (never straight to a hard block), and LegalDataAccessPolicyService guarantees read/export access is preserved through every one of those states — the offboarding/export governance path (OffboardingExportService) remains available throughout. The instance is never bricked: interactive write access degrades in a governed sequence while read/export access is explicitly retained.',
            ),
            'support_emergency_without_firm_approval' => new GovernanceMappingResult(
                item_key: 'support_emergency_without_firm_approval',
                item_label: 'Platform staff needs emergency access to a firm\'s data without prior firm approval',
                owning_class: \App\Services\EmergencyAccessGovernanceGapService::class,
                status: GovernanceMappingStatus::PartiallyImplemented,
                notes: 'support_access_requests.emergency_justification, requested_duration_minutes, and reason are all real, and SupportAccessPolicyService::logNotification()/logSessionAudit() are real. However, this control is governed by the ALREADY-TRACKED emergency_support_access_high_risk_approval_not_wired gap (see EmergencyAccessGovernanceGapService/ComplianceGapRegistryService): SupportAccessPolicyService/SupportAccessRequestService never call HighRiskPlatformChangePolicyService for HighRiskChangeType::EmergencySupportAccess, so emergency access proceeds the instant emergency_justification is non-empty, with no platform-admin eligibility check. This finding REFERENCES the existing gap; it does not duplicate it.',
            ),
            'legal_hold_blocks_delete' => new GovernanceMappingResult(
                item_key: 'legal_hold_blocks_delete',
                item_label: 'A legal hold must block deletion, offboarding, and key destruction',
                owning_class: \App\Services\LegalHoldService::class,
                status: GovernanceMappingStatus::Implemented,
                notes: 'LegalHoldService::hasActiveHold() is consulted by all three destructive-action governance services: DeletionGovernanceService::checkClearance() (returns DeletionRequestStatus::LegalHoldBlocked), OffboardingRequestService::evaluateReadiness() (returns OffboardingRequestStatus::LegalHoldBlocked), and KeyDestructionRequestService::checkClearance() (returns KeyDestructionRequestStatus::LegalHoldBlocked) — a real, consistently-applied block across every destructive path this catalog covers.',
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
     * Edge cases whose finding confirms legal data (documents, users,
     * read/export access) is never destroyed or abruptly withdrawn.
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function legalDataPreservation(): array
    {
        $keys = [
            'downgrade_seat_overuse', 'storage_limit_after_downgrade', 'subscription_payment_failed',
            'installment_failure_repeated_missed', 'client_wrong_or_duplicate_upload',
            'offline_license_expiry_air_gapped', 'legal_hold_blocks_delete',
        ];

        return array_values(array_intersect_key($this->all(), array_flip($keys)));
    }

    /**
     * Edge cases whose finding confirms a tenant/firm boundary is
     * respected (organization-wide behavior stays scoped, no cross-firm
     * leakage).
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function tenantBoundaryProtected(): array
    {
        $keys = ['organization_conflict_scope_adverse_parties', 'trust_overdraft_concurrent_requests'];

        return array_values(array_intersect_key($this->all(), array_flip($keys)));
    }

    /**
     * Edge cases whose finding confirms an irreversible/destructive
     * action is blocked or gated.
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function destructiveActionPrevention(): array
    {
        $keys = [
            'client_wrong_or_duplicate_upload', 'import_bad_data', 'legal_hold_blocks_delete',
            'fleet_migration_failure_mid_rollout',
        ];

        return array_values(array_intersect_key($this->all(), array_flip($keys)));
    }

    /**
     * Edge cases specifically concerned with whether an admin/firm
     * alert or staff-notification mechanism exists.
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function alertNotificationReadiness(): array
    {
        $keys = [
            'client_language_template_missing', 'stripe_disconnected', 'conflict_false_positive_common_name',
            'support_emergency_without_firm_approval',
        ];

        return array_values(array_intersect_key($this->all(), array_flip($keys)));
    }

    /**
     * Edge cases whose PartiallyImplemented classification is driven by
     * an ALREADY-TRACKED gap-register entry, referenced rather than
     * duplicated.
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function existingGapCrossReferences(): array
    {
        $keys = ['support_emergency_without_firm_approval'];

        return array_values(array_intersect_key($this->all(), array_flip($keys)));
    }

    /**
     * Edge-case findings that motivated a Section 35 gap-register
     * addition.
     *
     * @return array<int, GovernanceMappingResult>
     */
    public function gaps(): array
    {
        $keys = [
            'ai_entitlement_removed_with_pending_jobs',
            'client_language_template_missing',
            'stripe_disconnected',
        ];

        return array_values(array_filter(
            array_intersect_key($this->all(), array_flip($keys)),
            fn (GovernanceMappingResult $item) => $item->status === GovernanceMappingStatus::NotFound
                || ($item->item_key === 'stripe_disconnected' && $item->status === GovernanceMappingStatus::PartiallyImplemented),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return self::KEYS;
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
