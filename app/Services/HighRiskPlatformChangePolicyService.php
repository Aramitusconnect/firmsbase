<?php

namespace App\Services;

use App\Enums\HighRiskChangeRequestStatus;
use App\Enums\HighRiskChangeType;
use App\Models\HighRiskChangeRequest;
use App\Models\PlatformAdmin;
use App\ValueObjects\HighRiskChangeDecision;
use Illuminate\Support\Facades\DB;

/**
 * HighRiskPlatformChangePolicyService — the ONLY writer of
 * high_risk_change_requests, and the only service governing the
 * reason-required, two-person-approval-ready workflow FOUNDATION for:
 * trust_mode_activation, production_data_deletion,
 * payment_trust_setting_change, emergency_support_access. There is no
 * separate HighRiskChangeRequestService (documented decision from the
 * approved manifest) — this policy service owns both the decision
 * logic and the request/approve/deny state transitions, since a
 * separate service would only be a thin wrapper around the same table.
 *
 * This service NEVER executes the underlying change. Approving a
 * request here does not activate trust mode, does not delete
 * production data, and does not move trust/IOLTA money — those
 * remain entirely out of Phase 7 scope, gated behind whichever future
 * phase implements execution.
 */
class HighRiskPlatformChangePolicyService
{
    public function request(HighRiskChangeType $changeType, PlatformAdmin $requestedBy, string $reason): HighRiskChangeRequest
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required for every high-risk change request.');
        }

        $request = HighRiskChangeRequest::create([
            'change_type' => $changeType,
            'status' => HighRiskChangeRequestStatus::Pending,
            'reason' => $reason,
            'requested_by' => $requestedBy->id,
        ]);

        $this->audit($request, 'high_risk_change_requested');

        return $request;
    }

    public function firstApprove(HighRiskChangeRequest $request, PlatformAdmin $approver): HighRiskChangeDecision
    {
        $request->update([
            'first_approved_by' => $approver->id,
            'first_approved_at' => now(),
        ]);

        $status = $request->requiresSecondApproval()
            ? HighRiskChangeRequestStatus::FirstApproved
            : HighRiskChangeRequestStatus::Approved;

        $request->update(['status' => $status]);

        $this->audit($request, 'high_risk_change_first_approved');

        return new HighRiskChangeDecision($status, $request->requiresSecondApproval());
    }

    public function secondApprove(HighRiskChangeRequest $request, PlatformAdmin $approver): HighRiskChangeDecision
    {
        if (! $request->requiresSecondApproval()) {
            throw new \InvalidArgumentException('This change type does not require a second approval.');
        }

        if ($request->status !== HighRiskChangeRequestStatus::FirstApproved) {
            throw new \InvalidArgumentException('A request must be first-approved before it can be second-approved.');
        }

        if ($request->first_approved_by === $approver->id) {
            throw new \InvalidArgumentException('The second approver must be a different platform admin than the first approver.');
        }

        $request->update([
            'second_approved_by' => $approver->id,
            'second_approved_at' => now(),
            'status' => HighRiskChangeRequestStatus::Approved,
        ]);

        $this->audit($request, 'high_risk_change_second_approved');

        return new HighRiskChangeDecision(HighRiskChangeRequestStatus::Approved, false);
    }

    public function deny(HighRiskChangeRequest $request, PlatformAdmin $denier, string $reason): HighRiskChangeDecision
    {
        $request->update([
            'status' => HighRiskChangeRequestStatus::Denied,
            'denied_by' => $denier->id,
            'denied_at' => now(),
        ]);

        $this->audit($request, 'high_risk_change_denied', ['deny_reason' => $reason]);

        return new HighRiskChangeDecision(HighRiskChangeRequestStatus::Denied, false, $reason);
    }

    public function cancel(HighRiskChangeRequest $request): HighRiskChangeDecision
    {
        $request->update(['status' => HighRiskChangeRequestStatus::Cancelled]);

        $this->audit($request, 'high_risk_change_cancelled');

        return new HighRiskChangeDecision(HighRiskChangeRequestStatus::Cancelled, false);
    }

    private function audit(HighRiskChangeRequest $request, string $eventType, array $extraMetadata = []): void
    {
        DB::table('security_events')->insert([
            'firm_id' => null,
            'actor_type' => PlatformAdmin::class,
            'actor_id' => $request->requested_by,
            'event_type' => $eventType,
            'category' => 'high_risk_change',
            'metadata' => json_encode(array_merge([
                'high_risk_change_request_id' => $request->id,
                'change_type' => $request->change_type->value,
                'status' => $request->status->value,
            ], $extraMetadata)),
            'created_at' => now(),
        ]);
    }
}
