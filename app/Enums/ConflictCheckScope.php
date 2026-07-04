<?php

namespace App\Enums;

/**
 * ConflictCheckScope — conflict_check_runs.scope. Confirmed exact
 * values from the master plan's entity field catalog: "scope (firm |
 * organization)". Firm-scoped is the default (project rule: "Conflict
 * scope is firm-level by default"); Organization requires the parent
 * Organization's own conflict_scope to be explicitly set to
 * OrgWideOptIn (see App\Enums\ConflictScope from Phase 1) — a
 * conflict_check_run must never resolve to Organization scope just
 * because an organization exists, only when that opt-in is explicit.
 */
enum ConflictCheckScope: string
{
    case Firm = 'firm';
    case Organization = 'organization';
}
