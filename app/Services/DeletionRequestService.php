<?php

namespace App\Services;

use App\Enums\DeletionRequestStatus;
use App\Models\DeletionRequest;
use App\Models\Firm;
use App\Models\OffboardingExport;

/**
 * DeletionRequestService — creation/cancellation of a deletion_requests
 * row only. Clearance evaluation lives in DeletionGovernanceService;
 * approval lives in DeletionApprovalService. Approved decision #9:
 * subject_type/subject_id/subject_snapshot_json (not a fixed FK set),
 * since deletion governance may target many record types over time.
 */
class DeletionRequestService
{
    public function request(
        Firm $firm,
        string $subjectType,
        int $subjectId,
        string $reason,
        object $requestedBy,
        array $subjectSnapshot = [],
        ?OffboardingExport $offboardingExport = null,
    ): DeletionRequest {
        return (new TenantContextService())->runWithFirmContext($firm, fn () => DeletionRequest::create([
            'firm_id' => $firm->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_snapshot_json' => $subjectSnapshot,
            'reason' => $reason,
            'status' => DeletionRequestStatus::Requested,
            'offboarding_export_id' => $offboardingExport?->id,
            'requested_by_type' => $requestedBy::class,
            'requested_by_id' => $requestedBy->id,
            'requested_at' => now(),
        ]));
    }

    public function cancel(DeletionRequest $request, string $reason): DeletionRequest
    {
        return (new TenantContextService())->runWithFirmContext($request->firm_id, function () use ($request, $reason) {
            $request->update([
                'status' => DeletionRequestStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

            return $request->fresh();
        });
    }
}
