<?php

namespace App\Services;

use App\Enums\SupportAccessRequestStatus;
use App\Enums\SupportAccessType;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use App\Models\SupportAccessRequest;

/**
 * SupportAccessRequestService — the only writer of
 * support_access_requests. reason is always required (project rule:
 * "reason required"). Standard access additionally requires firm
 * approval before a session can start (enforced by
 * SupportAccessPolicyService, not here); emergency access requires
 * emergency_justification and bypasses the firm-approval step, but is
 * never exempt from the reason requirement.
 */
class SupportAccessRequestService
{
    public function request(
        Firm $firm,
        PlatformAdmin $requestedBy,
        SupportAccessType $accessType,
        string $reason,
        int $requestedDurationMinutes,
        ?string $emergencyJustification = null,
    ): SupportAccessRequest {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required for every support access request.');
        }

        if ($accessType === SupportAccessType::Emergency && trim((string) $emergencyJustification) === '') {
            throw new \InvalidArgumentException('Emergency support access requires an emergency_justification in addition to reason.');
        }

        return SupportAccessRequest::create([
            'firm_id' => $firm->id,
            'requested_by' => $requestedBy->id,
            'access_type' => $accessType,
            'reason' => $reason,
            'status' => SupportAccessRequestStatus::Requested,
            'requested_duration_minutes' => $requestedDurationMinutes,
            'emergency_justification' => $emergencyJustification,
        ]);
    }

    public function approve(SupportAccessRequest $request, FirmUser $approver): SupportAccessRequest
    {
        $request->update([
            'status' => SupportAccessRequestStatus::Approved,
            'approved_by' => $approver->id,
            'approved_at' => now(),
        ]);

        return $request->fresh();
    }

    public function deny(SupportAccessRequest $request, FirmUser $denier): SupportAccessRequest
    {
        $request->update([
            'status' => SupportAccessRequestStatus::Denied,
            'denied_by' => $denier->id,
            'denied_at' => now(),
        ]);

        return $request->fresh();
    }

    public function expire(SupportAccessRequest $request): SupportAccessRequest
    {
        $request->update(['status' => SupportAccessRequestStatus::Expired]);

        return $request->fresh();
    }
}
