<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * signature_request_recipients — second of a four-table, one-batch
 * FORCE ROW LEVEL SECURITY activation covering the e-signature domain
 * (Section 39A-7 Wave 7): signature_requests (2026_08_27_950035, its
 * own required parent), signature_request_recipients (this migration),
 * signature_events (2026_08_27_950037), and signature_certificates
 * (2026_08_27_950038). All four land together as one atomic batch — see
 * 950035's docblock for the full batch rationale. Must land no earlier
 * than signature_requests, which it carries a required, NOT NULL FK
 * into.
 *
 * Like the other tables in this batch, signature_request_recipients has
 * NO pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: signature_request_recipients carries a
 * direct, NOT NULL firm_id column, cascadeOnDelete() (see
 * database/migrations/2026_07_14_900002_create_signature_request_recipients_table.php:29).
 * The SignatureRequestRecipient model uses BelongsToTenant +
 * HasPublicUuid — a genuine tenant-owned row, not derived/platform/
 * shared. Also transitively tied to signature_requests via
 * signature_request_id (NOT NULL, cascadeOnDelete()). It is, in turn,
 * the FK parent of signature_events.signature_request_recipient_id and
 * signature_events.actor_recipient_id (both nullable) — landed before
 * signature_events (950037) for the same "no dependent table's policy
 * before its parent's" reason signature_requests was landed first.
 *
 * Command shape: all of SELECT/INSERT/UPDATE/DELETE are governed by a
 * single combined policy (no FOR clause). This table is NOT
 * append-only — status transitions (viewed/consented/signed/declined/
 * expired/voided) are all mutated in place via
 * SignatureRecipientWorkflowService and
 * SignatureRequestWorkflowService::send()/void() — so UPDATE must be
 * permitted under the same USING/WITH CHECK as SELECT; no asymmetric
 * read/write shape is warranted here.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. client_id / contact_id / party_id / recipient_firm_user_id (all
 *      nullable) — no composite foreign key or trigger ties any of
 *      them to the ACTUAL firm_id of the referenced row. Same class of
 *      gap as every prior wave's nullable-linked-entity columns.
 *   2. access_token_hash (nullable string) — confirmed, by direct
 *      inspection, written and read nowhere in application code today;
 *      purely a placeholder for a not-yet-built token mechanism. Not
 *      itself an ownership gap, but flagged here as a forward-looking
 *      column with no consumer yet.
 *   3. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row (or a signature_requests
 *      row) will always cascade-delete dependent
 *      signature_request_recipients rows regardless of which tenant's
 *      context is currently active. Expected, identical behavior to
 *      every other cascade-on-firms table already forced in this
 *      repository.
 *
 * Genuinely unusual finding, confirmed directly and worth stating
 * plainly rather than hiding: this table's INSERT path is currently
 * entirely dormant in production — no controller, service, job, or
 * command anywhere in app/ creates a signature_request_recipients row
 * outside of this table's own factory. Every production service only
 * reads or ->update()s an already-existing row. The RLS policy below
 * still governs INSERT identically to every other command, since
 * PostgreSQL RLS cannot special-case "this command path happens to be
 * unreachable today" — but readers of this migration should not infer
 * from any INSERT-path test coverage that a real production INSERT flow
 * has actually been exercised.
 *
 * Forward-looking design constraint (not a defect, not fixable now, no
 * code violates it): EstablishFirmTenantContext middleware resolves
 * firm context exclusively via $request->user()?->activeFirmUser(). A
 * future public/signed-link recipient-facing route (e.g. an
 * unauthenticated "view"/"consent"/"sign" endpoint) will have no
 * authenticated User at all, so that middleware will silently leave
 * context unset. Whoever builds that future controller MUST explicitly
 * derive firm_id from the recipient's own signatureRequest (via
 * TenantSafeSignatureAndPdfPolicyService::assertSignatureRequestRecipientBelongsToFirm(),
 * confirmed currently unwired, dead defense-in-depth code — a sound
 * pattern, just never called) and establish context themselves before
 * invoking any SignatureRecipientWorkflowService method. Nothing in
 * this migration needs to act on it since no such route exists yet.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'signature_request_recipients';

    private const POLICY = 'signature_request_recipients_tenant_isolation';

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
