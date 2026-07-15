<?php

namespace App\ValueObjects;

use App\Enums\TenantOwnershipClassification;

/**
 * TenantTableInventoryItem — one row of
 * RowLevelSecurityCoverageMappingService::fullTableInventory(), the
 * Wave 1A canonical 208-table inventory. Static/declarative, built
 * entirely from direct migration inspection — never from a live
 * database query.
 */
final readonly class TenantTableInventoryItem
{
    public function __construct(
        public string $table,
        public TenantOwnershipClassification $classification,
        /**
         * The FK/relationship chain establishing tenant ownership
         * (e.g. "document_id -> documents.firm_id"), the literal
         * string "self" for RootTenant (firms — its own primary key
         * IS the tenant identity), or null when there is no tenant
         * ownership path at all (Global/Audit/System) or it is not
         * yet resolved (Uncertain).
         */
        public ?string $ownershipPath,
        public string $notes,
    ) {}
}
