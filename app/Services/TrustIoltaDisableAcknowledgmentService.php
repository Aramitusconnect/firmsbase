<?php

namespace App\Services;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Models\DeploymentConfig;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use App\ValueObjects\HighRiskChangeDecision;

/**
 * TrustIoltaDisableAcknowledgmentService — approved decision #2's two
 * halves, both required before an operating-only dedicated law firm's
 * trust/IOLTA-disabled posture is considered valid:
 *   (a) platform-admin approval — reuses HighRiskPlatformChangePolicyService
 *       with the new HighRiskChangeType::OperatingOnlyTrustDisableAcknowledgment
 *       case, mirroring DedicatedCustomerTypeApprovalService/
 *       TrustModeActivationService exactly.
 *   (b) firm-side acknowledgment — written directly to the FOUR
 *       existing deployment_configs columns (no ninth table).
 *
 * isPostureValid() is the single method other code should call — it
 * requires BOTH halves, never either alone. Never modifies Phase 13
 * trust accounting services (TrustEligibilityService,
 * TrustModeActivationService, etc.) — this service only reads
 * firm_settings.trust_iolta_protection to decide relevance; it never
 * flips that flag itself.
 */
class TrustIoltaDisableAcknowledgmentService
{
    public function __construct(private readonly HighRiskPlatformChangePolicyService $highRiskPolicy) {}

    public function requestApproval(Firm $firm, PlatformAdmin $requestedBy, string $reason): HighRiskChangeRequest
    {
        return $this->highRiskPolicy->request(
            HighRiskChangeType::OperatingOnlyTrustDisableAcknowledgment,
            $requestedBy,
            $reason,
            ['firm_id' => $firm->id],
        );
    }

    public function firstApprove(HighRiskChangeRequest $request, PlatformAdmin $approver): HighRiskChangeDecision
    {
        $this->assertIsTrustDisableAcknowledgment($request);

        return $this->highRiskPolicy->firstApprove($request, $approver);
    }

    public function secondApprove(HighRiskChangeRequest $request, PlatformAdmin $approver): HighRiskChangeDecision
    {
        $this->assertIsTrustDisableAcknowledgment($request);

        return $this->highRiskPolicy->secondApprove($request, $approver);
    }

    public function isAdminApproved(Firm $firm): bool
    {
        return HighRiskChangeRequest::query()
            ->where('change_type', HighRiskChangeType::OperatingOnlyTrustDisableAcknowledgment->value)
            ->where('status', HighRiskChangeRequestStatus::Approved->value)
            ->get()
            ->contains(fn (HighRiskChangeRequest $request) => (int) ($request->metadata['firm_id'] ?? 0) === $firm->id);
    }

    /**
     * Writes the firm-side acknowledgment onto the firm's existing
     * deployment_configs row. Requires a DeploymentConfig to already
     * exist for this firm (created as part of the firm's dedicated/
     * private deployment setup) — this service is not the writer of
     * that row's other columns.
     *
     * The update is wrapped in the ROW's OWN firm_id context
     * (deployment_configs now carries permanent FORCE ROW LEVEL
     * SECURITY) — deliberately $config->firm_id, never the
     * acknowledging FirmUser's firm id or any other derived value.
     * $config->firm_id is this row's own owning column, so it is
     * guaranteed to satisfy the row's RLS policy; deriving context
     * from the acknowledging actor instead could silently mismatch
     * (e.g. cross-firm data, a bug elsewhere) and Eloquent's update()
     * does not throw on 0 affected rows — that mismatch would look
     * like success while actually being a silent no-op rather than a
     * clean, visible failure.
     */
    public function recordFirmAcknowledgment(
        DeploymentConfig $config,
        FirmUser $acknowledgedBy,
        string $acknowledgmentText,
        string $acknowledgmentVersion,
    ): DeploymentConfig {
        // fresh() is deliberately read INSIDE the same wrapped call as
        // the update — reading it after the context is torn back down
        // would itself fail closed under FORCE ROW LEVEL SECURITY (no
        // context active), returning null and breaking this method's
        // non-nullable return type.
        return (new TenantContextService)->runWithFirmContext(
            $config->firm_id,
            function () use ($config, $acknowledgedBy, $acknowledgmentText, $acknowledgmentVersion) {
                $config->update([
                    'trust_iolta_disabled_acknowledged_at' => now(),
                    'trust_iolta_disabled_acknowledged_by' => $acknowledgedBy->user_id,
                    'trust_iolta_disabled_acknowledgment_text' => $acknowledgmentText,
                    'trust_iolta_disabled_acknowledgment_version' => $acknowledgmentVersion,
                ]);

                return $config->fresh();
            },
        );
    }

    /**
     * Both halves required — never either alone.
     */
    public function isPostureValid(Firm $firm, DeploymentConfig $config): bool
    {
        return $this->isAdminApproved($firm) && $config->hasFirmAcknowledgedTrustIoltaDisabled();
    }

    private function assertIsTrustDisableAcknowledgment(HighRiskChangeRequest $request): void
    {
        if ($request->change_type !== HighRiskChangeType::OperatingOnlyTrustDisableAcknowledgment) {
            throw new \RuntimeException('This high-risk change request is not an operating_only_trust_disable_acknowledgment request.');
        }
    }
}
