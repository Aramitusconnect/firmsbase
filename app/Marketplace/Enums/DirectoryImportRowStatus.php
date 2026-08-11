<?php

declare(strict_types=1);

namespace App\Marketplace\Enums;

/**
 * DirectoryImportRowStatus — Mission 2 (MyAttorney Marketplace Core),
 * sections 53-55. Duplicate is a real, distinct state — a row matched
 * against an existing DirectoryFirm is never silently auto-merged
 * (section 52) or silently dropped; it stays Duplicate until an admin
 * explicitly decides to skip or update via MarketplaceImportApplyService.
 */
enum DirectoryImportRowStatus: string
{
    case Pending = 'pending';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';
    case Applied = 'applied';
    case Skipped = 'skipped';
}
