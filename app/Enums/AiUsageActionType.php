<?php

namespace App\Enums;

/**
 * AiUsageActionType — ai_usage_events.action_type. Closed set approved
 * for Phase 15 (decision #5). The first four are generic action
 * shapes; the last six mirror the Master Plan's six named high-risk
 * output categories exactly (AiApprovalCategory uses the same six
 * labels) so a usage event's action_type can be matched directly
 * against the high-risk category list without a separate mapping
 * table.
 */
enum AiUsageActionType: string
{
    case DraftGeneration = 'draft_generation';
    case Summarization = 'summarization';
    case RetrievalQuery = 'retrieval_query';
    case ToolAction = 'tool_action';
    case LegalResearchMemo = 'legal_research_memo';
    case LegalCitation = 'legal_citation';
    case DemandLetter = 'demand_letter';
    case CourtFilingDraft = 'court_filing_draft';
    case ClientLegalAdvice = 'client_legal_advice';
    case ClientFacingContent = 'client_facing_content';

    /**
     * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 5 —
     * classifying a pre-Firm prospect's described issue into a
     * practice area code. Deliberately NOT high-risk: it never
     * produces client-facing content, legal advice, or anything a
     * human must approve before use — see MarketplaceCapability and
     * this mission's own "AI may only classify pre-Firm issue category
     * into a practice area, never rank/diagnose/advise" boundary.
     * Structured (AiPromptRequest::responseSchemaKey =
     * 'practice_area_classification'), never free-text for this
     * action type.
     */
    case IntakeClassification = 'intake_classification';

    /**
     * The six action types that are always high-risk and always
     * require approval before use (Master Plan §22 acceptance
     * criteria; project rules 15/16/19/20). This list is intentionally
     * NOT configurable via firm_ai_settings.high_risk_requires_approval
     * — see that column's own docblock.
     */
    public function isHighRisk(): bool
    {
        return match ($this) {
            self::LegalResearchMemo,
            self::LegalCitation,
            self::DemandLetter,
            self::CourtFilingDraft,
            self::ClientLegalAdvice,
            self::ClientFacingContent => true,
            default => false,
        };
    }

    public function toApprovalCategory(): ?AiApprovalCategory
    {
        return match ($this) {
            self::LegalResearchMemo => AiApprovalCategory::LegalResearchMemo,
            self::LegalCitation => AiApprovalCategory::LegalCitation,
            self::DemandLetter => AiApprovalCategory::DemandLetter,
            self::CourtFilingDraft => AiApprovalCategory::CourtFilingDraft,
            self::ClientLegalAdvice => AiApprovalCategory::ClientLegalAdvice,
            self::ClientFacingContent => AiApprovalCategory::ClientFacingContent,
            default => null,
        };
    }
}
