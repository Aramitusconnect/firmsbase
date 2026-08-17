<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ProviderFeeDirection — FirmsVault Pay Gate A3 (v1.4 §36). The
 * provider-neutral direction of a fee evidence line. Deliberately only
 * two cases: a fee either takes money from the firm's provider balance
 * (Debit) or returns it (Credit). Categorization beyond direction is
 * carried as an opaque, nullable string — UNKNOWN is an acceptable
 * category (v1.4 §36), and no pricing engine exists (§48).
 */
enum ProviderFeeDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
