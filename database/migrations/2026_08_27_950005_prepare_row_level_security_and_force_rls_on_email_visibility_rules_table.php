<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-5 Wave 1 (independent checkpoint) — email_visibility_rules,
 * drawn from RowLevelSecurityCoverageMappingService::missingPreparedTables().
 * This checkpoint's migration/test batch lands independently of the other
 * Wave 1 tables (ai_retrieval_indexes, deployment_configs, firm_ai_settings);
 * the shared registry (RowLevelSecurityCoverageMappingService) and
 * docs/governance/rls-gap-registry.md are updated once by the coordinator
 * after all of this wave's checkpoints have landed — not by this migration.
 *
 * Like ai_retrieval_indexes/deployment_configs/firm_ai_settings before it,
 * this table has NO pre-existing policy to flip FORCE on for — no ENABLE
 * ROW LEVEL SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch: ENABLE
 * ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL SECURITY — never
 * leaving RLS-enabled-with-no-policy as an intermediate state.
 *
 * Table selection rationale: email_visibility_rules has a direct, NOT NULL
 * firm_id column (foreignId('firm_id')->constrained('firms')->cascadeOnDelete(),
 * see database/migrations/2026_07_12_900007_create_email_visibility_rules_table.php).
 * EmailVisibilityRuleFactory's bare (default) creation path already derives
 * firm_id from the created email_account (a NOT NULL, unique-per-row
 * belongs-to), so it cannot produce a cross-firm mismatch — no factory
 * change is needed for tenant consistency.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-implicit
 * convention established by the customer_success_health_scores checkpoint.
 *
 * Production-service note (no wiring change made in this batch): this
 * table currently has NO production writer — EmailVisibilityPolicyService::
 * resolveScope()/canView() are read-only query methods with ZERO callers
 * anywhere in app/, routes, or tests (confirmed by direct repository
 * search), so there is no call site to wrap in tenant context today. Any
 * FUTURE caller of resolveScope()/canView() (or any future writer of this
 * table) MUST establish tenant context first — via
 * TenantContextService::runWithFirmContext() at that call site, or by
 * running inside an already-context-bearing request/job — or the read/
 * write will silently fail closed (zero rows visible, or a "row-level
 * security policy" insert/update rejection) rather than surfacing the
 * OwnerOnly hard-default this service is documented to fall back to.
 *
 * Known, accepted, non-gap behavior: PostgreSQL's documented row-security
 * semantics exempt foreign-key ON DELETE CASCADE actions from row-security
 * policy evaluation entirely. Consequently, deleting a `firms`,
 * `email_accounts`, or `matters` row will always cascade-delete its
 * dependent email_visibility_rules row regardless of which tenant's
 * context is currently active in the session — this is expected, identical
 * behavior to every other cascade-on-firms table already forced in this
 * repository, not a gap introduced or left open by this migration.
 *
 * The table name is a single hardcoded string literal (never user input),
 * but is still validated against a strict identifier pattern before being
 * interpolated into SQL and is double-quoted, matching every prior
 * activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'email_visibility_rules';

    private const POLICY = 'email_visibility_rules_tenant_isolation';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

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

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Full rollback: this migration introduced the policy itself (there
     * was no pre-existing policy to merely un-FORCE), so down() must
     * remove all three effects up() added: FORCE, the policy, and row-
     * level security being enabled at all — restoring the table to its
     * true pre-this-migration (MISSING_PREPARED_TABLES) state.
     */
    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);
        $policy = $this->quoteIdentifier(self::POLICY);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
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
