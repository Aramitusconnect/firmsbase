<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * MarketplaceIntakeStatus — marketplace_intakes.status. Mission 3
 * (MyAttorney Conversion + AI Intake), checkpoint 1. Distinct from
 * FirmLeadStatus: this is the anonymous-visitor-facing lifecycle of a
 * single Firm-scoped secure intake session, not the Firm's own lead
 * pipeline (which only begins once ConvertMarketplaceProspectService
 * creates/resolves a firm_leads row on acceptance).
 *
 * Started -> InProgress -> Submitted -> UnderReview ->
 *   (ConflictReviewRequired ->) Accepted -> Converted
 *                              \-> Declined
 * Started/InProgress can also terminate at Abandoned (retention sweep,
 * checkpoint 14) instead of ever reaching Submitted.
 */
enum MarketplaceIntakeStatus: string
{
    case Started = 'started';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ConflictReviewRequired = 'conflict_review_required';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Converted = 'converted';
    case Abandoned = 'abandoned';
    case Expired = 'expired';

    /**
     * Statuses in which the prospect may still edit/submit answers.
     */
    public function isEditableByProspect(): bool
    {
        return in_array($this, [self::Started, self::InProgress], true);
    }

    /**
     * Statuses in which the Firm's own review action set applies.
     */
    public function isPendingFirmReview(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::ConflictReviewRequired], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Declined, self::Converted, self::Abandoned, self::Expired], true);
    }
}
