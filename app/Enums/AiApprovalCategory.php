<?php

namespace App\Enums;

/**
 * AiApprovalCategory — ai_approval_requests.category. The exact six
 * high-risk categories named in the Master Plan Phase 15 section
 * verbatim. This is a closed, non-extensible set for Phase 15 — adding
 * a category is a plan-level decision, not a runtime configuration
 * option.
 */
enum AiApprovalCategory: string
{
    case LegalResearchMemo = 'legal_research_memo';
    case LegalCitation = 'legal_citation';
    case DemandLetter = 'demand_letter';
    case CourtFilingDraft = 'court_filing_draft';
    case ClientLegalAdvice = 'client_legal_advice';
    case ClientFacingContent = 'client_facing_content';
}
