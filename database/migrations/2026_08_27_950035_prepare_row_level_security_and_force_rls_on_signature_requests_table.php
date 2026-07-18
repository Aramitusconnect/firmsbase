<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * signature_requests — first of a four-table, one-batch FORCE ROW
 * LEVEL SECURITY activation covering the e-signature domain (Section
 * 39A-7 Wave 7): signature_requests (this migration),
 * signature_request_recipients (2026_08_27_950036), signature_events
 * (2026_08_27_950037), and signature_certificates
 * (2026_08_27_950038). All four land together as one atomic batch, not
 * four independently-deployable checkpoints — every meaningful
 * workflow method across SignatureRequestWorkflowService/
 * SignatureRecipientWorkflowService/SignatureCertificateService writes
 * across at least 3 of these 4 tables in one un-transacted PHP call, so
 * a partial rollout risks a single logical operation succeeding on
 * tables with working context and silently failing partway through on
 * a newly-protected table. The shared registry
 * (RowLevelSecurityCoverageMappingService, still listing all four under
 * MISSING_PREPARED_TABLES at the point this migration lands on its own)
 * is updated once by the coordinator in a later, separate
 * wave-integration commit — not by this migration.
 *
 * Like every prior wave's first-landed table, signature_requests has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: signature_requests carries a direct, NOT
 * NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_14_900001_create_signature_requests_table.php:28). The
 * SignatureRequest model uses BelongsToTenant + HasPublicUuid — a
 * genuine tenant-owned workflow root, not derived/platform/shared. It
 * is the FK parent of signature_request_recipients.signature_request_id
 * (NOT NULL), signature_events.signature_request_id (NOT NULL), and
 * signature_certificates.signature_request_id (UNIQUE, NOT NULL) — none
 * of the other three tables in this batch are ever referenced BY this
 * one. Landed first so that no dependent table's own policy can ever
 * exist before its required parent's does.
 *
 * Command shape: all of SELECT/INSERT/UPDATE/DELETE are governed by a
 * single combined policy (no FOR clause). This table is NOT
 * append-only — status, attorney_reviewed_at, sent_at, completed_at,
 * voided_at, declined_at, etc. are all mutated in place by
 * SignatureRequestWorkflowService/SignatureRequestAggregationService/
 * SignatureCertificateService — so UPDATE must be permitted under the
 * same USING/WITH CHECK as SELECT; no asymmetric read/write shape is
 * warranted here.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. Dual-nullable source pointer (document_id/generated_document_id)
 *      — exactly one is ever populated, enforced only by
 *      SignatureRequestWorkflowService::create()'s own
 *      (($document === null) === ($generatedDocument === null)) check,
 *      never a DB XOR/CHECK constraint. Same gap class as
 *      document_hashes/pdf_view_events.
 *   2. No composite foreign key or trigger ties signature_requests.
 *      matter_id / .client_id (both nullable) to the ACTUAL firm_id of
 *      the matters/clients row they point at — only the caller-supplied
 *      $firm/$matter/$client parameters in create() enforce agreement
 *      today. This migration does not close that transitive gap.
 *   3. requested_by_firm_user_id (NOT NULL) / attorney_reviewed_by_
 *      firm_user_id (nullable) — standard actor-attribution FK gap,
 *      same class as every prior wave.
 *   4. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent signature_requests rows regardless of
 *      which tenant's context is currently active. Expected, identical
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
    private const TABLE = 'signature_requests';

    private const POLICY = 'signature_requests_tenant_isolation';

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
