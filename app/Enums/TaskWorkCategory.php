<?php

namespace App\Enums;

/**
 * TaskWorkCategory — Leverage Ratio Optimizer, item 6. The closed
 * vocabulary a Task's own optional `task_category` column may take —
 * confirmed by audit (Task has no categorization field at all before
 * this pass) that nothing equivalent exists to reuse. Never inferred
 * from a Task's free-text title/description; set explicitly at
 * creation time only (by a firm user, or by an Automation action's own
 * config), so a Task with no category is simply excluded from
 * task-role mismatch analysis rather than guessed at.
 *
 * Two informal tiers, matching the master spec's own examples exactly
 * (labeled "examples only" there, but adopted verbatim here as the
 * initial closed set — extending this enum for a genuinely new work
 * category is expected over time, the same "narrow starting set"
 * precedent DomainEventType's own docblock established):
 * attorney-tier work (substantive legal judgment) and support-tier
 * work (administrative/procedural). This enum itself does NOT encode
 * which role SHOULD perform a category — that is
 * TaskCategoryRoleExpectation's own, explicitly Firm-configured job
 * (see StaffingPolicyService), never a hardcoded assumption here.
 */
enum TaskWorkCategory: string
{
    case AttorneyLegalAnalysis = 'attorney_legal_analysis';
    case CourtAppearance = 'court_appearance';
    case LegalResearch = 'legal_research';
    case DraftingSubstantiveLegalDocument = 'drafting_substantive_legal_document';
    case ClientLegalAdvice = 'client_legal_advice';

    case DocumentCollection = 'document_collection';
    case DocumentFollowUp = 'document_follow_up';
    case DataEntry = 'data_entry';
    case FilingPreparation = 'filing_preparation';
    case ChecklistFollowUp = 'checklist_follow_up';
    case Scheduling = 'scheduling';
    case AdministrativeCommunication = 'administrative_communication';
}
