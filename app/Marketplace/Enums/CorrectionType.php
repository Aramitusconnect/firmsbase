<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * CorrectionType — Mission 2 (MyAttorney Marketplace Core), section
 * 51. Exactly the correction/removal categories the mission specifies.
 */
enum CorrectionType: string
{
    case IncorrectAddress = 'incorrect_address';
    case IncorrectPhone = 'incorrect_phone';
    case IncorrectPracticeArea = 'incorrect_practice_area';
    case AttorneyNoLongerAtFirm = 'attorney_no_longer_at_firm';
    case DuplicateListing = 'duplicate_listing';
    case FirmClosed = 'firm_closed';
    case RemovalRequest = 'removal_request';
    case ImpersonationOrClaimDispute = 'impersonation_or_claim_dispute';

    public function label(): string
    {
        return match ($this) {
            self::IncorrectAddress => 'Incorrect address',
            self::IncorrectPhone => 'Incorrect phone number',
            self::IncorrectPracticeArea => 'Incorrect practice area',
            self::AttorneyNoLongerAtFirm => 'Attorney no longer at this firm',
            self::DuplicateListing => 'Duplicate listing',
            self::FirmClosed => 'Firm has closed',
            self::RemovalRequest => 'Request removal of this listing',
            self::ImpersonationOrClaimDispute => 'Impersonation or claim dispute',
        };
    }
}
