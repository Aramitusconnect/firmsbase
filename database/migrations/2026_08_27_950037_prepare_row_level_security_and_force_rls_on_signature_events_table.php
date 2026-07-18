<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * signature_events — third of a four-table, one-batch FORCE ROW LEVEL
 * SECURITY activation covering the e-signature domain (Section 39A-7
 * Wave 7): signature_requests (2026_08_27_950035), signature_request_recipients
 * (2026_08_27_950036), signature_events (this migration), and
 * signature_certificates (2026_08_27_950038). All four land together as
 * one atomic batch — see 950035's docblock for the full batch
 * rationale. Must land after BOTH signature_requests (its own required,
 * NOT NULL parent FK) AND signature_request_recipients (950036),
 * because it carries TWO independent, nullable FKs into
 * signature_request_recipients (signature_request_recipient_id,
 * actor_recipient_id).
 *
 * Like the other tables in this batch, signature_events has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: signature_events carries a direct, NOT
 * NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_14_900004_create_signature_events_table.php:31). The
 * SignatureEvent model deliberately does NOT use BelongsToTenant
 * (mirrors the FormReviewEvent/GeneratedDocumentEvent precedent —
 * "firm_id kept for direct queries only"). This means once FORCE RLS
 * activates, RLS is the ONLY enforcement layer for this table — no
 * PHP-layer global scope narrows queries at all.
 *
 * Command shape: combined, symmetric, FOR ALL (no restrictive FOR
 * clause) — even though this table is append-only. This follows the
 * established Wave 5/6 precedent ("RLS governs firm-ownership, not
 * append-only-ness"): the model's booted() guard (see
 * app/Models/SignatureEvent.php, confirmed already present before this
 * batch — no companion model fix needed) already blocks every
 * Eloquent-layer UPDATE/DELETE unconditionally, for any firm. A
 * command-restricted RLS policy would be redundant with that guard and
 * would additionally risk accidentally widening INSERT/UPDATE/DELETE
 * behavior. The correct design is the same single combined policy as
 * every other table — PostgreSQL will still correctly deny a
 * cross-firm raw-SQL UPDATE/DELETE attempt (bypassing Eloquent) on
 * ownership grounds, independent of and in addition to the model
 * guard.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. signature_request_recipient_id and actor_recipient_id — two
 *      INDEPENDENT nullable foreign keys into the same parent table
 *      (signature_request_recipients), with no DB-level constraint
 *      tying either to the row's own firm_id, nor any constraint
 *      requiring the two to be mutually consistent (e.g. an event
 *      whose actor_recipient_id belongs to a different signature_request
 *      than signature_request_recipient_id). A two-column composite
 *      version of the standard single-nullable-FK gap seen in prior
 *      waves.
 *   2. document_hash_id (nullable) -> document_hashes (already FORCE
 *      RLS'd, Wave 6) — standard transitive gap.
 *   3. actor_firm_user_id (nullable) -> firm_users — standard
 *      actor-attribution FK gap, same class as every prior wave.
 *   4. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row (or a
 *      signature_requests row) will always cascade-delete dependent
 *      signature_events rows regardless of which tenant's context is
 *      currently active. Expected, identical behavior to every other
 *      cascade-on-firms table already forced in this repository.
 *
 * Readiness note: this is the highest-severity table in this batch to
 * get right before activation, precisely because it has no app-layer
 * safety net at all — but also the leaf-most writer
 * (SignatureEventLogger), so its wrap design is the simplest in the
 * domain.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'signature_events';

    private const POLICY = 'signature_events_tenant_isolation';

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
