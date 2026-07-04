<?php

namespace App\Enums;

/**
 * FirmLeadStatus — firm_leads.status. Canonical values from the master
 * plan's workflow state-machine table (Section 33, "Firm lead"row).
 * Conversion (new/contacted/consultation_scheduled/consultation_held ->
 * converted) is the ONLY path that may create a client — see
 * LeadConversionService. Lost leads follow retention policy, which is
 * owned by Phase 17 (Data Ownership, Offboarding, and Operational
 * Governance) — this enum only records the status, it does not
 * implement any retention/purge behavior itself.
 */
enum FirmLeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case ConsultationScheduled = 'consultation_scheduled';
    case ConsultationHeld = 'consultation_held';
    case Converted = 'converted';
    case Lost = 'lost';
    case Archived = 'archived';
}
