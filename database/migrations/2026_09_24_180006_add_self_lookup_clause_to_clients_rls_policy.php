<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 4 ("Plaid financial evidence add-on"), Client Portal
 * authentication foundation — Hop 2 of the corrected two-hop RLS
 * bootstrap, resolving Finding 2 of checkpoint4-security-review.md
 * (checkpoint4-combined-design.md §2.4/§5;
 * checkpoint4-design-matter-and-client-portal.md §2.4). Byte-for-byte
 * the same shape and the same reasoning as
 * 2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php
 * itself, applied a SECOND time against a different table.
 *
 * The problem this fixes: `EstablishClientPortalTenantContext` resolves
 * `ClientPortalUser.client_id` via Hop 1's own self-lookup (see
 * 2026_09_24_180002_..._client_portal_users_table.php), but `clients`
 * is independently FORCE-RLS-protected
 * (2026_07_30_900001_force_rls_on_clients_table.php) and is equally
 * invisible with no firm context active yet — reading
 * `Client::find($clientId)` at that point fails the same way
 * `firm_users` failed before `firm_users_self_lookup` existed. The
 * original design's first draft got this wrong (see
 * checkpoint4-security-review.md Finding 2 for the full trace):
 * `withoutGlobalScope('tenant')` alone does nothing against `clients`'
 * real FORCE ROW LEVEL SECURITY.
 *
 * The fix: a SEPARATE, ADDITIONAL, `FOR SELECT`-only policy —
 * `clients_self_lookup` — scoped by a NEW session setting,
 * `app.current_client_id`, distinct from
 * `app.current_client_portal_user_id` (which holds the ClientPortalUser
 * id, not the Client id). `app.current_client_id` is set STRICTLY from
 * `ClientPortalUser.client_id`, the plain attribute Hop 1's own already
 * self-lookup-resolved row carried — never from any request input,
 * query string, or header. See
 * TenantContextService::withClientSelfLookupContext() for where this
 * session setting is populated and cleared.
 *
 * IMPORTANT — this migration does NOT touch `clients`' existing
 * `clients_tenant_isolation` policy
 * (2026_07_05_600024_extend_row_level_security_to_phase_2_tenant_tables.php)
 * at all. PostgreSQL combines multiple permissive policies for the same
 * command with OR, and a `FOR SELECT`-only policy is never consulted
 * for INSERT/UPDATE/DELETE — so this policy can only ever widen what a
 * session may READ, never what it may WRITE. INSERT/UPDATE/DELETE on
 * `clients` remain governed solely by `clients_tenant_isolation`
 * (firm_id match), which is NULL/never-true with no firm context
 * active — exactly the fail-closed behavior every other RLS-forced
 * table already has.
 *
 * What this does NOT do: it does not let a client see any OTHER
 * client's `clients` row (id must match exactly, sourced only from the
 * current session's own already-authenticated, already-self-lookup-
 * resolved identity, never from any client-suppliable value); it does
 * not let anyone write ANY `clients` row through this policy (`FOR
 * SELECT` policies are never consulted for INSERT/UPDATE/DELETE); it
 * does not let a client see any firm's data through any OTHER table
 * (every other RLS policy in the codebase is untouched); and it grants
 * no more than what the client could already see anyway once real firm
 * context is established (their own client row). It only unlocks the
 * one bootstrap SELECT needed to discover which firm to activate that
 * context for.
 */
return new class extends Migration
{
    private const TABLE = 'clients';

    private const SELF_LOOKUP_POLICY = 'clients_self_lookup';

    public function up(): void
    {
        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::SELF_LOOKUP_POLICY)}
            ON {$this->quoteIdentifier(self::TABLE)}
            FOR SELECT
            USING (
                id = NULLIF(current_setting('app.current_client_id', true), '')::bigint
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement("DROP POLICY IF EXISTS {$this->quoteIdentifier(self::SELF_LOOKUP_POLICY)} ON {$this->quoteIdentifier(self::TABLE)}");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
