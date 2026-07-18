<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * form_review_events — fourth of a six-table, one-batch FORCE ROW
 * LEVEL SECURITY activation covering the documents/forms domain
 * (Section 39A-6 Wave 6): generated_documents (2026_08_27_950029),
 * form_drafts (2026_08_27_950030), generated_document_events
 * (2026_08_27_950031), form_review_events (this migration),
 * document_hashes (2026_08_27_950033), and pdf_view_events
 * (2026_08_27_950034). All six land together as one atomic batch — see
 * 950029's docblock for the full batch rationale. Must land after
 * form_drafts (950030) because this table carries a direct, NOT NULL,
 * cascadeOnDelete FK into it.
 *
 * Like the other tables in this batch, form_review_events has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: form_review_events carries a direct, NOT
 * NULL firm_id column, cascadeOnDelete() AND a direct, NOT NULL
 * form_draft_id FK, cascadeOnDelete() into form_drafts (see
 * database/migrations/2026_07_13_900007_create_form_review_events_table.php:19-20).
 * Identical structure to generated_document_events, table-for-table.
 * The FormReviewEvent model has NO BelongsToTenant — it is a pure
 * audit row queried explicitly by FormReviewService, not globally
 * scoped (Phase 8/9 audit-row precedent).
 *
 * Command shape: a single combined USING/WITH CHECK policy governs all
 * 4 commands at the RLS layer — RLS alone cannot distinguish "append"
 * from "update of an existing row" (both are governed by the same
 * firm-match condition). Append-only-ness must come from a separate
 * mechanism (see below).
 *
 * REQUIRED companion fix landing in this same commit (not optional,
 * not deferred): App\Models\FormReviewEvent gained a booted() guard,
 * mirroring App\Models\AiApprovalEvent's own, throwing LogicException
 * on any Eloquent update/delete of an existing row. This table is
 * append-only by convention (the sole writer,
 * FormReviewService::recordEvent(), only ever calls create() — no
 * update/delete call site exists anywhere in app/) but, having neither
 * BelongsToTenant nor a pre-existing append-only guard, it carried
 * ZERO independent enforcement of that fact — the exact same risk
 * profile Wave 5 treated as required-not-optional for
 * email_sync_events. As with email_sync_events/ai_approval_events/
 * generated_document_events, this migration's WITH CHECK clause
 * governs INSERT-time firm ownership only — it is NOT the append-only
 * mechanism, and does not by itself prevent an UPDATE or DELETE
 * against a row the active session's firm context legitimately owns.
 * That guarantee comes exclusively from the model-layer booted()
 * guard, not from RLS.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression) — not a FOR INSERT-only clause,
 * mirroring ai_approval_events'/email_sync_events'/
 * generated_document_events' own resolved precedent exactly.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. No composite foreign key or trigger ties form_review_events.
 *      firm_id to the ACTUAL firm_id of the form_drafts row its own
 *      form_draft_id points at. Today the only thing tying an audit
 *      row's firm_id to the correct firm is
 *      FormReviewService::recordEvent()'s own explicit
 *      'firm_id' => $draft->firm_id assignment. This migration does
 *      not close that transitive gap.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms or form_drafts row will
 *      always cascade-delete dependent form_review_events rows
 *      regardless of which tenant's context is currently active.
 *      Expected, identical behavior to every other cascade-on-firms
 *      table already forced in this repository, and orthogonal to the
 *      append-only guarantee above (a cascade delete is not an
 *      Eloquent update/delete of an existing row through the model).
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'form_review_events';

    private const POLICY = 'form_review_events_tenant_isolation';

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
     * true pre-this-migration (MISSING_PREPARED_TABLES) state. Does NOT
     * touch FormReviewEvent::booted()'s append-only guard, which is a
     * separate, independent model-layer mechanism this migration does
     * not own.
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
