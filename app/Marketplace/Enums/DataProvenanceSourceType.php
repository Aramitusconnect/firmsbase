<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * DataProvenanceSourceType — Mission 2, section 26. Every externally
 * sourced marketplace field must be able to record where it came from.
 * Exactly the approved source categories, no others invented.
 */
enum DataProvenanceSourceType: string
{
    case AdminEntered = 'admin_entered';
    case CsvImport = 'csv_import';
    case FirmSubmitted = 'firm_submitted';
    case AttorneySubmitted = 'attorney_submitted';
    case PublicRecord = 'public_record';
    case ProfessionalDirectory = 'professional_directory';
    case FirmWebsite = 'firm_website';
    case Google = 'google';
    case OtherApprovedSource = 'other_approved_source';
}
