<?php

namespace App\Services;

use App\Enums\OpportunityStatus;
use App\Enums\TrialRequestStatus;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\PlatformAdmin;
use App\Models\TrialRequest;

/**
 * Phase 3 (FirmsVault Platform Admin Control Center, "Billing and
 * Commercial Administration") addition: provision()/activate()/
 * expire()/convert() now accept an optional PlatformAdmin $actor and,
 * when one is supplied, record a
 * PlatformAdminAuditEventRecorder::recordPlatformEvent() row (the
 * firm-less variant — a TrialRequest is not tied to one firm; "a firm
 * does not yet exist at trial-request stage" per the RLS mapping
 * service's own note). When $actor is null (every existing caller —
 * no app-level call site currently passes one; only tests call these
 * methods directly today, including request() itself, which is
 * unmodified) behavior is byte-for-byte unchanged from before this
 * addition.
 */
class TrialRequestService
{
    private const AUDIT_CATEGORY = 'platform_billing';

    public function __construct(
        private readonly ConversionEventService $conversionEventService,
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function request(Opportunity $opportunity, ?\DateTimeInterface $expiresAt = null): TrialRequest
    {
        $trialRequest = TrialRequest::create([
            'opportunity_id' => $opportunity->id,
            'status' => TrialRequestStatus::Requested,
            'requested_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        $opportunity->update(['status' => OpportunityStatus::TrialActive]);

        $this->conversionEventService->recordDemoToTrial($opportunity, $trialRequest);

        return $trialRequest;
    }

    public function provision(TrialRequest $trialRequest, Organization $organization, ?PlatformAdmin $actor = null): TrialRequest
    {
        $trialRequest->update([
            'organization_id' => $organization->id,
            'status' => TrialRequestStatus::Provisioned,
            'provisioned_at' => now(),
        ]);

        $provisioned = $trialRequest->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'trial_provisioned',
                self::AUDIT_CATEGORY,
                [
                    'trial_request_id' => $provisioned->id,
                    'organization_id' => $organization->id,
                ],
            );
        }

        return $provisioned;
    }

    public function activate(TrialRequest $trialRequest, ?PlatformAdmin $actor = null): TrialRequest
    {
        $trialRequest->update(['status' => TrialRequestStatus::Active]);

        $activated = $trialRequest->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'trial_activated',
                self::AUDIT_CATEGORY,
                [
                    'trial_request_id' => $activated->id,
                ],
            );
        }

        return $activated;
    }

    public function expire(TrialRequest $trialRequest, ?PlatformAdmin $actor = null): TrialRequest
    {
        $trialRequest->update(['status' => TrialRequestStatus::Expired]);

        $expired = $trialRequest->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'trial_expired',
                self::AUDIT_CATEGORY,
                [
                    'trial_request_id' => $expired->id,
                ],
            );
        }

        return $expired;
    }

    public function convert(TrialRequest $trialRequest, ?PlatformAdmin $actor = null): TrialRequest
    {
        $trialRequest->update([
            'status' => TrialRequestStatus::Converted,
            'converted_at' => now(),
        ]);

        if ($trialRequest->organization !== null) {
            $this->conversionEventService->recordTrialToPaid($trialRequest, $trialRequest->organization);
        }

        $converted = $trialRequest->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'trial_converted',
                self::AUDIT_CATEGORY,
                [
                    'trial_request_id' => $converted->id,
                    'organization_id' => $converted->organization_id,
                ],
            );
        }

        return $converted;
    }
}
