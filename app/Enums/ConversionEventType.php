<?php

namespace App\Enums;

enum ConversionEventType: string
{
    case DemoToTrial = 'demo_to_trial';
    case TrialToPaid = 'trial_to_paid';
    case LeadToOpportunity = 'lead_to_opportunity';
    case OpportunityWon = 'opportunity_won';
    case OpportunityLost = 'opportunity_lost';
}
