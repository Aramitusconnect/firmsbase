<?php

namespace App\Enums;

/**
 * PartyEntityType — parties.entity_type. Distinguishes an individual
 * person from a company/organization party, so conflict checks can
 * match "companies" per the project rule without a separate companies
 * table — a company is simply a party (or contact) with entity_type =
 * Company.
 */
enum PartyEntityType: string
{
    case Individual = 'individual';
    case Company = 'company';
}
