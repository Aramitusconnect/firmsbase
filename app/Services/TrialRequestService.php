<?php

namespace App\Services;

use App\Enums\OpportunityStatus;
use App\Enums\TrialRequestStatus;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\TrialRequest;

class TrialRequestService
{
    public function __construct(
        private readonly ConversionEventService $conversionEventService,
    ) {
    }

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

    public function provision(TrialRequest $trialRequest, Organization $organization): TrialRequest
    {
        $trialRequest->update([
            'organization_id' => $organization->id,
            'status' => TrialRequestStatus::Provisioned,
            'provisioned_at' => now(),
        ]);

        return $trialRequest->fresh();
    }

    public function activate(TrialRequest $trialRequest): TrialRequest
    {
        $trialRequest->update(['status' => TrialRequestStatus::Active]);

        return $trialRequest->fresh();
    }

    public function expire(TrialRequest $trialRequest): TrialRequest
    {
        $trialRequest->update(['status' => TrialRequestStatus::Expired]);

        return $trialRequest->fresh();
    }

    public function convert(TrialRequest $trialRequest): TrialRequest
    {
        $trialRequest->update([
            'status' => TrialRequestStatus::Converted,
            'converted_at' => now(),
        ]);

        if ($trialRequest->organization !== null) {
            $this->conversionEventService->recordTrialToPaid($trialRequest, $trialRequest->organization);
        }

        return $trialRequest->fresh();
    }
}
