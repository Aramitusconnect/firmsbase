<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * form_drafts — second of a six-table, one-batch FORCE ROW LEVEL
 * SECURITY activation covering the documents/forms domain (Section
 * 39A-6 Wave 6): generated_documents (2026_08_27_950029), form_drafts
 * (this migration), generated_document_events (2026_08_27_950031),
 * form_review_events (2026_08_27_950032), document_hashes
 * (2026_08_27_950033), and pdf_view_events (2026_08_27_950034). All
 * six land together as one atomic batch — see 950029's docblock for
 * the full batch rationale. Placed second, adjacent to
 * generated_documents, as the second independent workflow root in the
 * batch — there is no FK dependency ordering requirement relative to
 * 950029; it could equally have been landed first.
 *
 * Like generated_documents, form_drafts has NO pre-existing policy to
 * flip FORCE on for — no ENABLE ROW LEVEL SECURITY and no CREATE
 * POLICY exist for it anywhere yet. This migration does all three
 * steps required by docs/governance/future-table-requirements.md
 * #4/#5 in one batch: ENABLE ROW LEVEL SECURITY, CREATE POLICY, and
 * FORCE ROW LEVEL SECURITY — never leaving RLS-enabled-with-no-policy
 * as an intermediate state.
 *
 * Table selection rationale: form_drafts carries a direct, NOT NULL
 * firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_13_900005_create_form_drafts_table.php:26). matter_id is
 * also NOT NULL here (a real schema difference from
 * generated_documents.matter_id, which is nullable). The FormDraft
 * model uses BelongsToTenant + HasPublicUuid. It carries no FK of its
 * own into any of the other five tables in this batch;
 * form_review_events.form_draft_id (NOT NULL) references it, not the
 * reverse.
 *
 * Command shape: all of SELECT/INSERT/UPDATE/DELETE are governed by a
 * single combined policy (no FOR clause). This table is NOT
 * append-only — status/used_sample_mapping/reviewed_by_firm_user_id/
 * reviewed_at/approved_at are all updated in place by
 * FormReviewService (the model's own booted() guard only protects
 * form_template_version_id's own immutability, not the whole row) —
 * so UPDATE must be permitted under the same USING/WITH CHECK as
 * SELECT; no asymmetric read/write shape is warranted here.
 *
 * REQUIRED companion fix landing in this same commit (not optional,
 * not deferred): FirmCommandCenterAggregationService::snapshot()'s
 * formsReadyForReviewCount metric read this table with NO
 * runWithFirmContext() wrap at all, unlike every one of its 13 sibling
 * metrics — this was a live, confirmed bug (silent undercount-to-zero
 * once this table is FORCE RLS'd, not a cross-firm leak, since the
 * explicit where('firm_id', $firm->id) combines with RLS via AND).
 * Fixed in the same commit as this migration.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. No composite foreign key or trigger ties form_drafts.matter_id
 *      (NOT NULL) / .client_id (nullable) to the ACTUAL firm_id of the
 *      matters/clients row they point at. Only
 *      FormDraftGenerationService::generate()'s own
 *      $matter->firm_id assignment enforces this today. This migration
 *      does not close that transitive gap.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent form_drafts rows regardless of which
 *      tenant's context is currently active. Expected, identical
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
    private const TABLE = 'form_drafts';

    private const POLICY = 'form_drafts_tenant_isolation';

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
