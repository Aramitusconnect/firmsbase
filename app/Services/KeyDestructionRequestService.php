<?php

namespace App\Services;

use App\Enums\KeyDestructionRequestStatus;
use App\Enums\LegalHoldScope;
use App\Enums\OffboardingExportStatus;
use App\Enums\RetentionRecordType;
use App\Models\Firm;
use App\Models\KeyDestructionRequest;
use App\Models\OffboardingRequest;
use App\Models\PlatformAdmin;
use App\Models\TenantEncryptionKey;
use App\ValueObjects\KeyDestructionClearanceResult;

/**
 * KeyDestructionRequestService — the pre-approval clearance gate.
 * Requires a completed offboarding export, retention clearance, and no
 * active legal hold BEFORE a request may be submitted for two-person
 * approval (KeyDestructionApprovalService). Never executes the
 * destruction itself — that is KeyDestructionExecutionService, and only
 * after approval.
 */
class KeyDestructionRequestService
{
    public function __construct(
        private readonly RetentionPolicyService $retentionPolicyService,
        private readonly LegalHoldService $legalHoldService,
    ) {
    }

    public function request(
        Firm $firm,
        PlatformAdmin $requestedBy,
        string $reason,
        ?OffboardingRequest $offboardingRequest = null,
        ?TenantEncryptionKey $tenantEncryptionKey = null,
    ): KeyDestructionRequest {
        return (new TenantContextService())->runWithFirmContext($firm, fn () => KeyDestructionRequest::create([
            'firm_id' => $firm->id,
            'offboarding_request_id' => $offboardingRequest?->id,
            'tenant_encryption_key_id' => $tenantEncryptionKey?->id,
            'status' => KeyDestructionRequestStatus::Requested,
            'reason' => $reason,
            'requested_by_platform_admin_id' => $requestedBy->id,
            'requested_at' => now(),
        ]));
    }

    public function checkClearance(KeyDestructionRequest $request): KeyDestructionClearanceResult
    {
        $exportCleared = $request->offboarding_request_id !== null
            && \App\Models\OffboardingExport::query()
                ->where('offboarding_request_id', $request->offboarding_request_id)
                ->where('status', OffboardingExportStatus::Verified->value)
                ->exists();

        if (! $exportCleared) {
            return new KeyDestructionClearanceResult(false, false, false, 'A verified offboarding export is required before key destruction can be requested.');
        }

        $firm = $request->firm;
        $policy = $this->retentionPolicyService->resolveEffectivePolicyFor($firm, RetentionRecordType::Firm);
        $retentionCleared = $this->retentionPolicyService
            ->isRetentionCleared($policy, $firm->created_at ?? now())
            ->cleared;

        if (! $retentionCleared) {
            return new KeyDestructionClearanceResult(true, false, false, 'Retention policy has not cleared for this firm.');
        }

        $legalHoldCleared = (new TenantContextService())->runWithFirmContext($firm, fn () => ! $this->legalHoldService->hasActiveHold($firm, LegalHoldScope::Firm));

        if (! $legalHoldCleared) {
            return new KeyDestructionClearanceResult(true, true, false, 'An active legal hold blocks key destruction.');
        }

        return new KeyDestructionClearanceResult(true, true, true);
    }

    public function submitForApproval(KeyDestructionRequest $request): KeyDestructionRequest
    {
        $clearance = $this->checkClearance($request);

        if (! $clearance->isClear()) {
            $status = match (true) {
                ! $clearance->exportCleared => KeyDestructionRequestStatus::ExportClearancePending,
                ! $clearance->retentionCleared => KeyDestructionRequestStatus::RetentionClearancePending,
                default => KeyDestructionRequestStatus::LegalHoldBlocked,
            };

            (new TenantContextService())->runWithFirmContext($request->firm_id, fn () => $request->update(['status' => $status]));

            throw new \RuntimeException($clearance->reason ?? 'Key destruction request is not yet clear for approval.');
        }

        return (new TenantContextService())->runWithFirmContext($request->firm_id, function () use ($request) {
            $request->update(['status' => KeyDestructionRequestStatus::PendingApproval]);

            return $request->fresh();
        });
    }

    public function cancel(KeyDestructionRequest $request, string $reason): KeyDestructionRequest
    {
        return (new TenantContextService())->runWithFirmContext($request->firm_id, function () use ($request, $reason) {
            $request->update([
                'status' => KeyDestructionRequestStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_reason' => $reason,
            ]);

            return $request->fresh();
        });
    }
}
