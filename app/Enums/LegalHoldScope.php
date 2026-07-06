<?php

namespace App\Enums;

/**
 * LegalHoldScope — the fixed 4-level hierarchy a legal_holds row can
 * apply to (approved decision #9: explicit nullable FKs per level —
 * firm_id/client_id/matter_id/document_id — rather than a single
 * polymorphic subject pair, since these levels are fixed and important).
 */
enum LegalHoldScope: string
{
    case Firm = 'firm';
    case Client = 'client';
    case Matter = 'matter';
    case Document = 'document';
}
