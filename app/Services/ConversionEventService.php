<?php

namespace App\Services;

use App\Enums\ConversionEventType;
use App\Models\ConversionEvent;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\PlatformLead;
use App\Models\TrialRequest;

/**
 * ConversionEventService — the only writer of conversion_events. Records
 * every pipeline-stage transition (lead -> opportunity -> demo/trial ->
 * conversion/lost) as an immutable event row. Other Phase 7 services
 * call these record* methods at the moment of transition rather than
 * writing conversion_events directly.
 */
class ConversionEventService
{
    public function recordLeadToOpportunity(PlatformLead $lead, Opportunity $opportunity): ConversionEvent
    {
        return ConversionEvent::create([
            'platform_lead_id' => $lead->id,
            'opportunity_id' => $opportunity->id,
            'event_type' => ConversionEventType::LeadToOpportunity,
            'occurred_at' => now(),
        ]);
    }

    public function recordDemoToTrial(Opportunity $opportunity, TrialRequest $trialRequest): ConversionEvent
    {
        return ConversionEvent::create([
            'platform_lead_id' => $opportunity->platform_lead_id,
            'opportunity_id' => $opportunity->id,
            'trial_request_id' => $trialRequest->id,
            'event_type' => ConversionEventType::DemoToTrial,
            'occurred_at' => now(),
        ]);
    }

    public function recordTrialToPaid(TrialRequest $trialRequest, Organization $organization): ConversionEvent
    {
        return ConversionEvent::create([
            'opportunity_id' => $trialRequest->opportunity_id,
            'trial_request_id' => $trialRequest->id,
            'organization_id' => $organization->id,
            'event_type' => ConversionEventType::TrialToPaid,
            'occurred_at' => now(),
        ]);
    }

    public function recordOpportunityWon(Opportunity $opportunity): ConversionEvent
    {
        return ConversionEvent::create([
            'platform_lead_id' => $opportunity->platform_lead_id,
            'opportunity_id' => $opportunity->id,
            'event_type' => ConversionEventType::OpportunityWon,
            'occurred_at' => now(),
        ]);
    }

    public function recordOpportunityLost(Opportunity $opportunity, string $reason): ConversionEvent
    {
        return ConversionEvent::create([
            'platform_lead_id' => $opportunity->platform_lead_id,
            'opportunity_id' => $opportunity->id,
            'event_type' => ConversionEventType::OpportunityLost,
            'occurred_at' => now(),
            'metadata' => ['lost_reason' => $reason],
        ]);
    }
}
