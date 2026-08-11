<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * VerificationDimension — Mission 2 (MyAttorney Marketplace Core),
 * section 24. Verification is multi-dimensional, never one Boolean —
 * exactly the seven dimensions the mission specifies, each verified
 * (or not) independently of the others.
 */
enum VerificationDimension: string
{
    case FirmAuthority = 'firm_authority';
    case AttorneyIdentity = 'attorney_identity';
    case AttorneyLicense = 'attorney_license';
    case OfficeAddress = 'office_address';
    case Phone = 'phone';
    case DomainEmail = 'domain_email';
    case Membership = 'membership';
}
