<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365 provider —
 * checkpoint2-combined-design.md §1.1/§2 P-4) addition to
 * `firm_integrations`: two new, independent, nullable columns. Both are
 * framework-level (not Microsoft-365-specific) — every future
 * capability-selecting/tenant-scoped provider (e.g. Google Workspace)
 * reuses the same two columns.
 *
 * `requested_capabilities_json` — nullable JSON array of capability-key
 * strings (reuses the existing App\Integrations\Enums\ResourceType
 * vocabulary — no new enum), the firm's PRE-CONNECT capability
 * selection. Deliberately a separate column from the existing
 * `scopes_granted_json` (an OUTPUT, populated post-hoc from the
 * provider's own callback response, read-only from the firm's
 * perspective) — conflating the two would make it impossible to tell
 * "what we asked for" from "what we got back".
 *
 * `external_tenant_id` — nullable string, the captured Microsoft
 * tenant id (or equivalent coarse-grained "whole connected
 * organization" identifier for a future provider). Distinct from the
 * existing `external_account_id` (the specific connected user account
 * within that tenant) — a structurally different, coarser-grained
 * concept this table previously had no column for at all.
 * `ProviderConnectionService::finishCallback()` applies the exact same
 * capture-if-null / hash_equals()-compare-and-reject-on-mismatch
 * pattern to this column that it already applies to
 * `external_account_id` on reauthorization (checkpoint2-security-review.md
 * Finding 1). Unlike `external_account_id`, `disconnect()` does NOT
 * null this column — see ProviderConnectionService::disconnect()'s own
 * inline comment for why that is a deliberate non-issue, not an
 * oversight: no uniqueness constraint depends on `external_tenant_id`,
 * and `startConnection()`'s own docblock confirms a reconnect after a
 * full disconnect always creates a brand-new `firm_integrations` row,
 * so the old row's stale value is never read again.
 *
 * Additive-only migration over an existing, already-FORCE-RLS'd table
 * (`database/migrations/2026_09_02_020002_prepare_row_level_security_and_force_rls_on_firm_integrations_table.php`)
 * — no RLS policy change needed, neither column carries isolation
 * semantics of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_integrations', function (Blueprint $table) {
            $table->json('requested_capabilities_json')->nullable()->after('scopes_granted_json');
            $table->string('external_tenant_id')->nullable()->after('external_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('firm_integrations', function (Blueprint $table) {
            $table->dropColumn(['requested_capabilities_json', 'external_tenant_id']);
        });
    }
};
