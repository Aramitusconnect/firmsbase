<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * document_hashes — fifth of a six-table, one-batch FORCE ROW LEVEL
 * SECURITY activation covering the documents/forms domain (Section
 * 39A-6 Wave 6): generated_documents (2026_08_27_950029), form_drafts
 * (2026_08_27_950030), generated_document_events (2026_08_27_950031),
 * form_review_events (2026_08_27_950032), document_hashes (this
 * migration), and pdf_view_events (2026_08_27_950034). All six land
 * together as one atomic batch — see 950029's docblock for the full
 * batch rationale. Not transactionally coupled to form_drafts/
 * form_review_events at all, but must land no earlier than
 * generated_documents (950029), which it carries a nullable FK into.
 *
 * REQUIRED companion fix landing in this same commit (not optional,
 * not deferred, in an out-of-scope file incidentally touched by this
 * wave): SignatureCertificateService::generate()'s GeneratedDocument
 * read branch was completely unwrapped in runWithFirmContext(),
 * relying on a comment that becomes false the moment generated_documents
 * is forced (this same wave, 950029). Fixed in the same commit as this
 * migration so the read path into document_hashes via
 * DocumentHashService::latestForGeneratedDocument() continues to work
 * correctly once both tables are FORCE RLS'd.
 *
 * Like the other tables in this batch, document_hashes has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: document_hashes carries a direct, NOT
 * NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_14_900003_create_document_hashes_table.php:25). It carries a
 * dual-nullable-parent-pointer shape: document_id (nullable, restrictOnDelete,
 * into documents — already FORCE RLS'd, out of scope) and
 * generated_document_id (nullable, restrictOnDelete, into
 * generated_documents — 950029, this wave). Exactly one of the two is
 * ever set in practice, enforced ONLY by DocumentHashService's two
 * distinct methods (recordForDocument/recordForGeneratedDocument),
 * never by a DB-level XOR/CHECK constraint — this migration does not
 * add one; see the deferred-gaps list below.
 *
 * Command shape: the DocumentHash model already has a booted() guard
 * (app/Models/DocumentHash.php) confirmed append-only via
 * DocumentHashIsImmutableTest — no companion model fix needed here,
 * unlike generated_document_events/form_review_events. The RLS policy
 * still governs all 4 commands identically (INSERT-time ownership
 * check is what matters; UPDATE/DELETE are separately blocked by the
 * existing model guard, same relationship as email_sync_events' own
 * RLS-vs-guard split).
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. The dual-nullable-parent-pointer XOR gap described above — no
 *      DB-level constraint prevents both document_id and
 *      generated_document_id from being set simultaneously, or both
 *      left null.
 *   2. No composite foreign key or trigger ties document_hashes.firm_id
 *      to the ACTUAL firm_id of whichever parent (documents or
 *      generated_documents) is set. Only DocumentHashService's own
 *      explicit firm_id assignment enforces this today.
 *   3. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent document_hashes rows regardless of
 *      which tenant's context is currently active. Expected, identical
 *      behavior to every other cascade-on-firms table already forced
 *      in this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'document_hashes';

    private const POLICY = 'document_hashes_tenant_isolation';

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
