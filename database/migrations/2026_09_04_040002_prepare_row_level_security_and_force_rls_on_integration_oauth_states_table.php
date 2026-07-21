<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * integration_oauth_states — standard canonical direct-tenant FORCE ROW
 * LEVEL SECURITY activation (Checkpoint 5), byte-for-byte mirroring the
 * base tenant-isolation policy shape of firm_integrations/
 * integration_credentials' own RLS migrations (Checkpoints 3/4),
 * itself modeled on this codebase's Wave 11 webhook_subscriptions
 * activation — the proven precedent for every one of this repository's
 * forced tables.
 *
 * PLUS one additional, narrow, `FOR SELECT`-only policy —
 * `integration_oauth_states_self_lookup` — required to bootstrap the
 * OAuth callback lookup BEFORE any firm context can be known (the
 * authenticated user's browser returns from the provider carrying only
 * an opaque state value; there is no firm_id anywhere in that request
 * yet). This is byte-for-byte the proven `firm_users_self_lookup` shape
 * (see 2026_08_10_900001_add_self_lookup_clause_to_firm_users_rls_policy.php),
 * independently verified correct by Agent H's review (item 3):
 *
 *   - `FOR SELECT` only: PostgreSQL never consults a FOR SELECT-only
 *     permissive policy for INSERT/UPDATE/DELETE, so it structurally
 *     cannot widen write authorization — the exact bug class
 *     firm_users_self_lookup's own docblock documents fixing (an
 *     earlier version that OR'd the self-lookup clause into the same
 *     USING expression as the base policy also silently widened WITH
 *     CHECK, since Postgres defaults WITH CHECK to the same expression
 *     when none is given separately).
 *   - Scoped by CALLER IDENTITY (`app.current_user_id`, a session
 *     setting only an authenticated user's own id can populate via
 *     TenantContextService::withUserContext()), not by any row
 *     attribute or request-suppliable value — this is precisely what
 *     distinguishes it from a rejected `credential_type = '...'`-style
 *     carve-out.
 *   - Returns only rows where `initiating_user_id` exactly equals the
 *     caller's own id — a caller cannot vary this to enumerate another
 *     user's row.
 *
 * IntegrationOAuthStateService::resolveAndConsume() additionally filters
 * the Step-A lookup query on `opaque_token_hash` itself (never relying
 * on this policy as the SOLE predicate) — per Agent H's review item 2,
 * required so that a user with more than one connect/reauthorize flow
 * in flight (multiple tabs/providers) cannot have an arbitrary one of
 * their own pending states returned instead of the specific one their
 * callback request is actually continuing. That is a functional-
 * correctness requirement enforced in application code; this policy is
 * the independent, second-layer tenant-isolation guarantee underneath
 * it regardless.
 *
 * Command shape for the base policy: combined, symmetric, FOR ALL —
 * integration_oauth_states is fully mutable via
 * IntegrationOAuthStateService/ProviderConnectionService, matching the
 * canonical template used throughout this rollout. The self-lookup
 * policy is layered UNDERNEATH it (PostgreSQL combines multiple
 * permissive policies for the same command with OR) — a session with
 * real firm context active continues to see exactly what the base
 * policy alone would already show it; the self-lookup policy only ever
 * WIDENS what a session with ONLY app.current_user_id set (no firm
 * context at all) may SELECT, and only to that one caller's own rows.
 *
 * Known, deliberately-deferred gap (identical to every prior forced
 * table in this rollout): PostgreSQL's documented row-security
 * semantics exempt foreign-key ON DELETE CASCADE actions from row-
 * security policy evaluation entirely — deleting a firms (or
 * firm_integrations) row will always cascade-delete dependent
 * integration_oauth_states rows regardless of which tenant's context is
 * currently active.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'integration_oauth_states';

    private const POLICY = 'integration_oauth_states_tenant_isolation';

    private const SELF_LOOKUP_POLICY = 'integration_oauth_states_self_lookup';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);
        $selfLookupPolicy = $this->quoteIdentifier(self::SELF_LOOKUP_POLICY);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

        DB::statement(<<<SQL
            CREATE POLICY {$policy}
            ON {$table}
            USING (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
            WITH CHECK (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
            )
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY {$selfLookupPolicy}
            ON {$table}
            FOR SELECT
            USING (
                initiating_user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint
            )
        SQL);

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Full rollback: this migration introduced both policies itself
     * (there was no pre-existing policy to merely un-FORCE), so down()
     * must remove every effect up() added: FORCE, both policies, and
     * row-level security being enabled at all — restoring the table to
     * its true pre-this-migration state.
     */
    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);
        $selfLookupPolicy = $this->quoteIdentifier(self::SELF_LOOKUP_POLICY);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY {$selfLookupPolicy} ON {$table}");
        DB::statement("DROP POLICY {$policy} ON {$table}");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
