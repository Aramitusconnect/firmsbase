<?php

namespace App\Services;

use App\Enums\LegalHoldScope;
use App\Enums\OffboardingExportStatus;
use App\Enums\OffboardingRequestStatus;
use App\Enums\RetentionRecordType;
use App\Models\Firm;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\ValueObjects\OffboardingReadinessResult;

/**
 * OffboardingRequestService — the firm-level state machine sequencing
 * export -> retention clearance -> legal-hold clearance -> ready-for-
 * deletion -> completed. Never deletes or destroys anything itself;
 * key destruction and hard deletion are separate, independently-gated
 * workflows (KeyDestructionRequestService, DeletionGovernanceService)
 * that MAY be linked to an OffboardingRequest via offboarding_request_id
 * but are not driven directly by this service.
 *
 * offboarding_requests carries FORCE ROW LEVEL SECURITY (see
 * database/migrations/2026_08_29_970006_prepare_row_level_security_and_force_rls_on_offboarding_requests_table.php).
 * advance()'s new whole-body wrap intentionally NESTS around
 * evaluateReadiness()'s existing inner wrap (added in Wave 8 to fix the
 * legal_holds fail-open bug, at the same firm) — confirmed structurally
 * safe per TenantContextService's snapshot/restore-in-finally
 * semantics; that inner wrap is left unmodified here.
 *
 * FVACC mission-wide final hardening review finding (MEDIUM): advance(),
 * complete(), and cancel() used to take no actor parameter and record no
 * attribution anywhere — no PlatformAdminAuditEventRecorder call, and no
 * completed_by_/cancelled_by_/advanced_by_ column exists on this table
 * (unlike verify()'s sibling OffboardingExportService, which stores
 * verified_by_platform_admin_id directly on the row). Fixed by adding an
 * optional ?PlatformAdmin $actor to all three and calling
 * PlatformAdminAuditEventRecorder::record() (the firm-scoped variant —
 * every OffboardingRequest has a real firm_id) when one is supplied,
 * mirroring the actor/audit pattern already established throughout this
 * mission rather than adding new schema columns. Behavior is unchanged
 * when $actor is null.
 */
class OffboardingRequestService
{
    private const AUDIT_CATEGORY = 'data_export_governance';

    public function __construct(
        private readonly RetentionPolicyService $retentionPolicyService,
        private readonly LegalHoldService $legalHoldService,
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function request(Firm $firm, PlatformAdmin $requestedBy, string $reason): OffboardingRequest
    {
        return (new TenantContextService)->runWithFirmContext($firm, fn () => OffboardingRequest::create([
            'firm_id' => $firm->id,
            'status' => OffboardingRequestStatus::Requested,
            'reason' => $reason,
            'requested_by_platform_admin_id' => $requestedBy->id,
            'requested_at' => now(),
        ]));
    }

    public function evaluateReadiness(OffboardingRequest $request): OffboardingReadinessResult
    {
        $exportCompleted = $request->exports()
            ->where('status', OffboardingExportStatus::Verified->value)
            ->exists();

        // A relation already cached on $request (e.g. by an earlier
        // export/offboarding step in the same request lifecycle) could
        // hold a stale Firm snapshot — always re-fetch here so a
        // governance-critical clearance decision never reads a stale
        // created_at.
        $firm = $request->firm()->firstOrFail();
        $policy = $this->retentionPolicyService->resolveEffectivePolicyFor($firm, RetentionRecordType::Firm);
        $retentionCleared = $this->retentionPolicyService
            ->isRetentionCleared($policy, $firm->created_at ?? now())
            ->cleared;

        $legalHoldCleared = (new TenantContextService)->runWithFirmContext($firm, fn () => ! $this->legalHoldService->hasActiveHold($firm, LegalHoldScope::Firm));

        return new OffboardingReadinessResult($exportCompleted, $retentionCleared, $legalHoldCleared);
    }

    public function advance(OffboardingRequest $request, ?PlatformAdmin $actor = null): OffboardingRequest
    {
        return (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request, $actor) {
            $readiness = $this->evaluateReadiness($request);

            $status = match (true) {
                ! $readiness->exportCompleted => OffboardingRequestStatus::ExportPending,
                ! $readiness->legalHoldCleared => OffboardingRequestStatus::LegalHoldBlocked,
                ! $readiness->retentionCleared => OffboardingRequestStatus::RetentionClearancePending,
                default => OffboardingRequestStatus::ReadyForDeletion,
            };

            $request->update(['status' => $status]);

            $fresh = $request->fresh();

            if ($actor !== null) {
                $this->auditRecorder->record($fresh->firm, $actor, 'offboarding_request.advanced', self::AUDIT_CATEGORY, [
                    'offboarding_request_id' => $fresh->id,
                    'resulting_status' => $fresh->status->value,
                ]);
            }

            return $fresh;
        });
    }

    public function complete(OffboardingRequest $request, ?PlatformAdmin $actor = null): OffboardingRequest
    {
        return (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request, $actor) {
            $request->update(['status' => OffboardingRequestStatus::Completed, 'completed_at' => now()]);

            $fresh = $request->fresh();

            if ($actor !== null) {
                $this->auditRecorder->record($fresh->firm, $actor, 'offboarding_request.completed', self::AUDIT_CATEGORY, [
                    'offboarding_request_id' => $fresh->id,
                ]);
            }

            return $fresh;
        });
    }

    public function cancel(OffboardingRequest $request, string $reason, ?PlatformAdmin $actor = null): OffboardingRequest
    {
        return (new TenantContextService)->runWithFirmContext($request->firm_id, function () use ($request, $reason, $actor) {
            $request->update([
                'status' => OffboardingRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
            ]);

            $fresh = $request->fresh();

            if ($actor !== null) {
                $this->auditRecorder->record($fresh->firm, $actor, 'offboarding_request.cancelled', self::AUDIT_CATEGORY, [
                    'offboarding_request_id' => $fresh->id,
                ]);
            }

            return $fresh;
        });
    }
}
