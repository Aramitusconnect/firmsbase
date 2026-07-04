<?php

namespace App\Services;

use App\Enums\OpportunityStatus;
use App\Enums\PlatformLeadStatus;
use App\Models\Opportunity;
use App\Models\PlatformAdmin;
use App\Models\PlatformLead;

class OpportunityService
{
    public function __construct(
        private readonly ConversionEventService $conversionEventService,
    ) {
    }

    public function createFromLead(PlatformLead $lead, array $attributes = []): Opportunity
    {
        $opportunity = Opportunity::create([
            'platform_lead_id' => $lead->id,
            'assigned_to' => $attributes['assigned_to'] ?? $lead->assigned_to,
            'status' => OpportunityStatus::Open,
            'estimated_seats' => $attributes['estimated_seats'] ?? null,
            'estimated_mrr_cents' => $attributes['estimated_mrr_cents'] ?? null,
            'expected_close_at' => $attributes['expected_close_at'] ?? null,
        ]);

        $lead->update(['status' => PlatformLeadStatus::Qualified]);

        $this->conversionEventService->recordLeadToOpportunity($lead, $opportunity);

        return $opportunity;
    }

    public function assignTo(Opportunity $opportunity, PlatformAdmin $admin): Opportunity
    {
        $opportunity->update(['assigned_to' => $admin->id]);

        return $opportunity->fresh();
    }

    public function updateStatus(Opportunity $opportunity, OpportunityStatus $status): Opportunity
    {
        $opportunity->update(['status' => $status]);

        return $opportunity->fresh();
    }

    public function markWon(Opportunity $opportunity): Opportunity
    {
        $opportunity->update([
            'status' => OpportunityStatus::Won,
            'closed_at' => now(),
        ]);

        $this->conversionEventService->recordOpportunityWon($opportunity);

        return $opportunity->fresh();
    }

    public function markLost(Opportunity $opportunity, string $reason): Opportunity
    {
        $opportunity->update([
            'status' => OpportunityStatus::Lost,
            'lost_reason' => $reason,
            'closed_at' => now(),
        ]);

        $this->conversionEventService->recordOpportunityLost($opportunity, $reason);

        return $opportunity->fresh();
    }
}
