<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * generated_documents — first of a six-table, one-batch FORCE ROW
 * LEVEL SECURITY activation covering the documents/forms domain
 * (Section 39A-6 Wave 6): generated_documents (this migration),
 * form_drafts (2026_08_27_950030), generated_document_events
 * (2026_08_27_950031), form_review_events (2026_08_27_950032),
 * document_hashes (2026_08_27_950033), and pdf_view_events
 * (2026_08_27_950034). All six land together as one atomic batch, not
 * six independently-deployable checkpoints — FormReviewService and
 * DocumentReviewService each write two of these tables in one
 * un-transacted PHP call today, and document_hashes/pdf_view_events
 * both carry nullable FKs into generated_documents, so a partial
 * rollout would be policy-safe per table but caller-unsafe across the
 * group. The shared registry (RowLevelSecurityCoverageMappingService,
 * still listing all six under MISSING_PREPARED_TABLES at the point
 * this migration lands on its own) is updated once by the coordinator
 * in a later, separate wave-integration commit — not by this
 * migration.
 *
 * Like every prior wave's first-landed table, generated_documents has
 * NO pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: generated_documents carries a direct, NOT
 * NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_13_900013_create_generated_documents_table.php:28). The
 * GeneratedDocument model uses BelongsToTenant + HasPublicUuid — a
 * genuine tenant-owned workflow root, not derived/platform/shared. It
 * carries no FK of its own into any of the other five tables in this
 * batch; document_hashes.generated_document_id and
 * pdf_view_events.generated_document_id (both nullable) and
 * generated_document_events.generated_document_id (NOT NULL) all
 * reference it, not the reverse. Landed first so that the two
 * nullable-FK tables (document_hashes, pdf_view_events) never have a
 * moment where their own policy exists but the table they optionally
 * reference doesn't.
 *
 * Command shape: all of SELECT/INSERT/UPDATE/DELETE are governed by a
 * single combined policy (no FOR clause). This table is NOT
 * append-only — status/used_sample_content/reviewed_by_firm_user_id/
 * reviewed_at/approved_at are all updated in place by
 * DocumentReviewService — so UPDATE must be permitted under the same
 * USING/WITH CHECK as SELECT; no asymmetric read/write shape is
 * warranted here.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. No composite foreign key or trigger ties generated_documents.
 *      matter_id / .client_id (both nullable) to the ACTUAL firm_id of
 *      the matters/clients row they point at. Only
 *      DocumentGenerationService::generate()'s own caller-supplied
 *      $firmId parameter (not derived from $matter/$client themselves)
 *      enforces this today. This migration does not close that
 *      transitive gap.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent generated_documents rows regardless of
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
    private const TABLE = 'generated_documents';

    private const POLICY = 'generated_documents_tenant_isolation';

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
