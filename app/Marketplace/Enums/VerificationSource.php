<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * VerificationSource — Mission 2 (MyAttorney Marketplace Core),
 * section 24's own "source" field, per dimension. Exactly the approved
 * verification-method categories, no others invented — mirrors
 * DataProvenanceSourceType's own closed-vocabulary convention.
 */
enum VerificationSource: string
{
    case AdminDocumentReview = 'admin_document_review';
    case ThirdPartyBarRecord = 'third_party_bar_record';
    case DomainOwnershipCheck = 'domain_ownership_check';
    case PhoneConfirmation = 'phone_confirmation';
    case ClaimApprovalEvidence = 'claim_approval_evidence';
    case MembershipActivation = 'membership_activation';
    case OtherApprovedSource = 'other_approved_source';
}
