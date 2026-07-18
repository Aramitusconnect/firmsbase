<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * legal_holds — first of a six-table, one-batch FORCE ROW LEVEL
 * SECURITY activation covering the governance/support/platform domain
 * (Section 39A-8 Wave 8): legal_holds (this migration), deletion_requests
 * (2026_08_28_960002), key_destruction_requests (2026_08_28_960003),
 * support_access_requests (2026_08_28_960004), support_access_sessions
 * (2026_08_28_960005), deployment_health_checks (2026_08_28_960006).
 * legal_holds lands first because LegalHoldService::hasActiveHold()/
 * checkHold() is the single clearance check every other table in this
 * batch (deletion_requests, key_destruction_requests) and one
 * out-of-batch caller (offboarding_requests, unchanged this wave) must
 * call before allowing deletion or key destruction — its own wrap
 * boundaries land first so those callers' new wraps are meaningful.
 *
 * legal_holds has NO pre-existing policy to flip FORCE on for — no
 * ENABLE ROW LEVEL SECURITY and no CREATE POLICY exist for it anywhere
 * yet. This migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: legal_holds carries a direct, NOT NULL
 * firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_28_900002_create_legal_holds_table.php:19). The LegalHold
 * model uses BelongsToTenant + HasPublicUuid — a genuine tenant-owned
 * row, not derived/platform/shared. client_id/matter_id/document_id are
 * fixed, mutually-exclusive-by-scope nullable FKs (not polymorphic).
 *
 * Command shape: combined, symmetric, FOR ALL — legal_holds is fully
 * mutable via LegalHoldService::release() (status transitions Active ->
 * Released), so unlike some append-only tables in prior waves there is
 * no model-level booted() guard restricting UPDATE — RLS still governs
 * all 4 commands via one combined policy, matching every other table in
 * this batch and the canonical template used throughout this rollout.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. client_id/matter_id/document_id (each nullable, cascadeOnDelete())
 *      — no composite foreign key or trigger ties clients/matters/
 *      documents.firm_id to this row's own firm_id; only
 *      LegalHoldService::place()'s caller-supplied Firm/Client/Matter/
 *      Document objects are trusted, with no explicit cross-firm
 *      assertion performed by place() itself. Documented here as an
 *      accepted, residual application-layer gap — same posture as every
 *      other single-hop-FK gap accepted in this rollout (e.g.
 *      expense_categories).
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent legal_holds rows regardless of which
 *      tenant's context is currently active. Expected, identical
 *      behavior to every other cascade-on-firms table already forced in
 *      this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'legal_holds';

    private const POLICY = 'legal_holds_tenant_isolation';

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
