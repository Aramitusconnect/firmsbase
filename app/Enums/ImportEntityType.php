<?php

namespace App\Enums;

/**
 * ImportEntityType — the 11 entity types the Import Center supports,
 * exactly matching the approved Phase 8 scope list. Duplicate detection
 * is only implemented for the subset the approved scope names
 * (Client, Contact, Matter, Invoice, PaymentPlan, Document, Party) —
 * see ImportDuplicateDetectionService's own docblock for the other 4
 * (FirmLead, TimeEntry, ConflictRecord, Template), which deliberately
 * always return a no-match result.
 */
enum ImportEntityType: string
{
    case FirmLead = 'firm_lead';
    case Client = 'client';
    case Contact = 'contact';
    case Matter = 'matter';
    case Party = 'party';
    case Document = 'document';
    case TimeEntry = 'time_entry';
    case Invoice = 'invoice';
    case PaymentPlan = 'payment_plan';
    case ConflictRecord = 'conflict_record';
    case Template = 'template';
}
