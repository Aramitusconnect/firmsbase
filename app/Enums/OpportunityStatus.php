<?php

namespace App\Enums;

enum OpportunityStatus: string
{
    case Open = 'open';
    case DemoScheduled = 'demo_scheduled';
    case TrialActive = 'trial_active';
    case ProposalSent = 'proposal_sent';
    case Won = 'won';
    case Lost = 'lost';
}
