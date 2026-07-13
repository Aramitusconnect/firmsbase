<?php

namespace App\Enums;

/**
 * TenantOwnershipClassification — the closed set of tenant-ownership
 * shapes a database table can hold, per the Wave 1A canonical
 * 208-table inventory (Section 39A-4B) built on top of the Wave 0
 * read-only audit. Declarative only: assigning a table one of these
 * cases is metadata describing what future RLS policy shape (if any)
 * would be structurally correct for it — it does not itself grant,
 * change, or activate any RLS behavior. See
 * RowLevelSecurityCoverageMappingService::fullTableInventory() for
 * where every one of the 208 tables is assigned exactly one case.
 *
 * - DirectTenant: the table has its own NOT NULL firm_id column that
 *   IS the tenant-ownership boundary. This is the
 *   PREPARED_TABLES/MISSING_PREPARED_TABLES universe.
 * - InheritedTenant: no firm_id column of its own; ownership flows
 *   through a foreign key to a tenant-owned (or Hybrid) parent row,
 *   one or more hops away (e.g. document_versions -> documents.firm_id).
 * - Pivot: a many-to-many bridge/join table between two entities,
 *   itself owned only transitively through one of its own FKs (e.g.
 *   matter_parties, task_dependencies, api_key_scopes).
 * - Hybrid: carries its own NULLABLE firm_id (or an equivalent
 *   nullable tenant-reference column) where null legitimately means
 *   "platform/global scope" and non-null means "this firm's row" —
 *   both are valid, simultaneous states for the same table (e.g.
 *   api_keys, announcements, document_templates).
 * - Global: platform-wide catalog, configuration, account, or billing
 *   table with no tenant relevance at all.
 * - Audit: platform-wide, append-only event/log table with no tenant
 *   relevance and no ongoing catalog/reference meaning of its own —
 *   narrower than Global, reserved for pure log tables (e.g.
 *   commission_events, conversion_events).
 * - System: Laravel/framework infrastructure table (cache, queue,
 *   session, password-reset scaffolding) with no application-level
 *   tenant meaning whatsoever.
 * - RootTenant: the tenant row itself (firms). There is no parent
 *   firm_id column to point to, because the table's own primary key
 *   IS the tenant identity — ownership path is "self".
 * - Uncertain: ownership genuinely undetermined pending a separate,
 *   still-open investigation (currently only offboarding_exports).
 *   Do not infer or invent an ownership path for a table in this
 *   state — leave it Uncertain until that investigation resolves it.
 *
 * Temporary/Obsolete cases were considered (Wave 0 found zero matches
 * for either across the 208-table inventory) and are intentionally
 * omitted rather than added as permanently-unused cases.
 */
enum TenantOwnershipClassification: string
{
    case DirectTenant = 'direct_tenant';
    case InheritedTenant = 'inherited_tenant';
    case Pivot = 'pivot';
    case Hybrid = 'hybrid';
    case Global = 'global';
    case Audit = 'audit';
    case System = 'system';
    case RootTenant = 'root_tenant';
    case Uncertain = 'uncertain';
}
