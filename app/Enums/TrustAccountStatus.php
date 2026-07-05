<?php

namespace App\Enums;

/**
 * TrustAccountStatus — the lifecycle of a firm's IOLTA trust bank
 * account record. No real bank integration exists anywhere in this
 * codebase (project rule) — this status tracks the record only.
 */
enum TrustAccountStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
