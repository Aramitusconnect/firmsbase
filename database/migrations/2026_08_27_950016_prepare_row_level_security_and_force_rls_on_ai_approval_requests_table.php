<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ai_approval_requests — a single, independent FORCE ROW LEVEL SECURITY
 * activation checkpoint drawn from RowLevelSecurityCoverageMappingService::
 * missingPreparedTables() (Section 39A-4A.1 inventory sweep). Like
 * matter_expenses (39A-5, Wave 2) before it, this table has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state. The shared registry
 * (RowLevelSecurityCoverageMappingService, still listing
 * ai_approval_requests under MISSING_PREPARED_TABLES at the point this
 * migration lands on its own) is updated once by the coordinator in a
 * later, separate wave-integration commit — not by this migration.
 *
 * Combined-batch note: this migration is deliberately paired with
 * 2026_08_27_950017_prepare_row_level_security_and_force_rls_on_ai_approval_events_table.php
 * as a single reviewed unit, NOT because the two tables share a single
 * RLS policy or schema, but because both are written exclusively by
 * AiApprovalWorkflowService (see that service's own docblock), whose
 * approve()/reject() methods perform one write to EACH table inside a
 * single logical operation — activating FORCE on one table without the
 * other would leave that one operation half-protected. Each table has
 * its own independent firm_id column, its own independent policy below,
 * and its own independent down().
 *
 * (a) Policy anchor: ai_approval_requests carries its OWN direct, NOT
 * NULL firm_id column (see database/migrations/2026_07_23_900005_
 * create_ai_approval_requests_table.php) — the policy predicate below
 * reads that column directly, exactly like every other DirectTenant
 * table's policy in this registry. It does not need to look through
 * matter_id, ai_usage_event_id, or encryption_key_id to find a firm.
 *
 * (b) Known, deliberately-deferred gap: no composite foreign key or
 * trigger ties ai_approval_requests.firm_id to the ACTUAL firm_id of
 * the row ai_usage_event_id/matter_id/encryption_key_id point at.
 * PostgreSQL RLS on ai_approval_requests alone cannot see into the
 * ai_usage_events/matters/tenant_encryption_keys tables to cross-check
 * this — that would require a structurally different EXISTS-against-
 * parent policy (a separate, unaddressed architectural question, per
 * this registry's own scope boundary note), not the standard
 * `firm_id = current_setting(...)` template used here. Today, the ONLY
 * things preventing an ai_approval_requests row from pointing at a
 * usage event/matter/encryption key belonging to a different firm than
 * its own firm_id are AiApprovalWorkflowService::submit()'s own
 * construction (it always derives ai_usage_event_id and
 * encryption_key_id from the same $firm parameter it stamps onto
 * firm_id) and, in tests, the factory fix landing alongside this
 * migration. This migration does not close that database-layer gap —
 * it is stated here, not hidden.
 *
 * (c) Parent table scope: ai_usage_events and tenant_encryption_keys
 * are handled independently elsewhere in this rollout (tenant_encryption_keys
 * already has FORCE active as of 2026_08_25_930016). ai_usage_events'
 * own FORCE state is out of scope for this migration — this migration
 * makes ai_approval_requests ITSELF safe under FORCE; it does not
 * change ai_usage_events' RLS posture at all.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-
 * implicit convention established since customer_success_health_scores.
 *
 * Known, accepted, non-gap behavior: PostgreSQL's documented row-
 * security semantics exempt foreign-key ON DELETE CASCADE actions from
 * row-security policy evaluation entirely. ai_approval_requests.firm_id,
 * requested_by, ai_usage_event_id, and encryption_key_id are all
 * ->cascadeOnDelete() (matter_id is ->nullOnDelete()), so deleting a
 * firms/users/ai_usage_events/tenant_encryption_keys row will always
 * cascade as configured regardless of which tenant's context is
 * currently active — expected, identical behavior to every other
 * cascade-on-firms table already forced in this repository, not a gap
 * introduced or left open by this migration.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'ai_approval_requests';

    private const POLICY = 'ai_approval_requests_tenant_isolation';

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
