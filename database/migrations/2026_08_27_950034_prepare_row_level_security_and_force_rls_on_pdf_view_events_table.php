<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * pdf_view_events — sixth and last of a six-table, one-batch FORCE ROW
 * LEVEL SECURITY activation covering the documents/forms domain
 * (Section 39A-6 Wave 6): generated_documents (2026_08_27_950029),
 * form_drafts (2026_08_27_950030), generated_document_events
 * (2026_08_27_950031), form_review_events (2026_08_27_950032),
 * document_hashes (2026_08_27_950033), and pdf_view_events (this
 * migration). All six land together as one atomic batch — see
 * 950029's docblock for the full batch rationale. Fully independent of
 * form_drafts/form_review_events/generated_document_events as a writer
 * concern, but must land no earlier than generated_documents (950029),
 * which it carries a nullable FK into. Placed last since it is the
 * most independent table in the batch — zero controllers/routes
 * anywhere touch it; the only caller chain is
 * PdfAnnotationService::annotate() -> PdfViewEventService::recordAnnotation(),
 * itself with zero production callers.
 *
 * Like the other tables in this batch, pdf_view_events has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: pdf_view_events carries a direct, NOT
 * NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_14_900006_create_pdf_view_events_table.php:26). Same
 * dual-nullable-parent shape as document_hashes (document_id/
 * generated_document_id, both nullable, both restrictOnDelete) — same
 * genuine, deliberately-deferred XOR gap. Additionally has a genuine
 * external-actor path: viewer_recipient_id (nullable FK into
 * signature_request_recipients, out of scope, unprepared),
 * representing a KNOWN, TRACKED, same-firm signature recipient, not an
 * anonymous/firmless identity.
 *
 * Command shape: the PdfViewEvent model already has a booted() guard
 * (app/Models/PdfViewEvent.php) identical in wording style to
 * DocumentHash's — confirmed the ONLY one of the three event-shaped
 * tables in this wave with the guard already present before this
 * batch; no companion model fix needed. RLS governs all 4 commands
 * identically.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. The dual-nullable-parent-pointer XOR gap described above (same
 *      class of gap as document_hashes) — no DB-level constraint
 *      prevents both document_id and generated_document_id from being
 *      set simultaneously, or both left null.
 *   2. No composite foreign key or trigger ties pdf_view_events.firm_id
 *      to the ACTUAL firm_id of whichever parent (documents or
 *      generated_documents) is set. Only PdfViewEventService's own
 *      explicit firm_id assignment enforces this today.
 *   3. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent pdf_view_events rows regardless of
 *      which tenant's context is currently active. Expected, identical
 *      behavior to every other cascade-on-firms table already forced
 *      in this repository.
 *   4. Forward-looking design constraint (not a defect, not fixable
 *      now, no code violates it): EstablishFirmTenantContext
 *      middleware resolves firm context exclusively via
 *      $request->user()?->activeFirmUser(). A future public/signed-link
 *      recipient-viewing route will have no authenticated User at all,
 *      so that middleware will silently leave context unset. Whoever
 *      builds that future controller MUST explicitly derive firm_id
 *      from the recipient's own signatureRequest (via
 *      TenantSafeSignatureAndPdfPolicyService::assertSignatureRequestRecipientBelongsToFirm())
 *      and wrap the entire PdfViewEventService call themselves. Nothing
 *      in this migration needs to act on it since no such route exists
 *      yet.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'pdf_view_events';

    private const POLICY = 'pdf_view_events_tenant_isolation';

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
