<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * DirectoryImportBatchStatus — Mission 2 (MyAttorney Marketplace
 * Core), sections 53-55. Mirrors the shape of the existing generic
 * ImportBatch lifecycle (Staged -> Validated -> Previewed -> Confirmed
 * -> Applied), with one addition specific to the marketplace: a real
 * SourceApprovalRequired state (section 27 — unresolved source rights
 * classify as SOURCE_APPROVAL_REQUIRED without blocking the whole
 * marketplace) sitting between Previewed and Confirmed, gated on the
 * importing admin's explicit source_rights_confirmed attestation.
 */
enum DirectoryImportBatchStatus: string
{
    case Staged = 'staged';
    case Validated = 'validated';
    case Previewed = 'previewed';
    case SourceApprovalRequired = 'source_approval_required';
    case Confirmed = 'confirmed';
    case Applied = 'applied';
    case Cancelled = 'cancelled';
}
