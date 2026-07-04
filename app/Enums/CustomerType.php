<?php

namespace App\Enums;

/**
 * CustomerType — firms.customer_type. USA SaaS initially supports
 * law_firm customers only (project rule 8). legal_specialist exists in
 * the enum because the schema is not law-firm-only at the type level,
 * but no legal_specialist-facing UI/workflow is built in Phase 1 —
 * legal_specialist customers must never see trust/IOLTA workflows or
 * law-firm-only terminology (project rule 7), which is enforced at the
 * entitlement/UI layer in later phases, not here.
 */
enum CustomerType: string
{
    case LawFirm = 'law_firm';
    case LegalSpecialist = 'legal_specialist';
}
