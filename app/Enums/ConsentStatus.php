<?php

namespace App\Enums;

/**
 * ConsentStatus — Master Plan v21 Section 5 canonical enum: consent_status.
 */
enum ConsentStatus: string
{
    case Granted = 'granted';
    case Declined = 'declined';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case Unknown = 'unknown';
}
