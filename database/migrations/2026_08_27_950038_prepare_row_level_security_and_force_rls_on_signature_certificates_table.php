<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * signature_certificates — fourth and last of a four-table, one-batch
 * FORCE ROW LEVEL SECURITY activation covering the e-signature domain
 * (Section 39A-7 Wave 7): signature_requests (2026_08_27_950035),
 * signature_request_recipients (2026_08_27_950036), signature_events
 * (2026_08_27_950037), and signature_certificates (this migration). All
 * four land together as one atomic batch — see 950035's docblock for
 * the full batch rationale. It has no FK dependency on
 * signature_request_recipients/signature_events at all (only on
 * signature_requests, 950035, and the out-of-batch, already-forced
 * document_hashes), so strictly by FK graph it could land as early as
 * second. It is placed last because (a) it is the terminal artifact of
 * the whole workflow — SignatureCertificateService::generate() requires
 * the request already Signed and requires at least one signature_events
 * row to already exist — so functionally, though not FK-wise, it
 * depends on signature_events having landed; and (b) it carries the
 * tightest, most order-sensitive DB constraint (UNIQUE signature_request_id)
 * of the four tables.
 *
 * Like the other tables in this batch, signature_certificates has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: signature_certificates carries a direct,
 * NOT NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_14_900005_create_signature_certificates_table.php:25). The
 * SignatureCertificate model uses BelongsToTenant + HasPublicUuid — a
 * genuine tenant-owned row, not derived/platform/shared.
 * signature_request_id is UNIQUE + restrictOnDelete() (a genuine,
 * DB-enforced one-certificate-per-request guarantee — this is a CLOSED
 * gap, not deferred). document_hash_id is NOT NULL, restrictOnDelete()
 * -> document_hashes (already forced, Wave 6).
 *
 * Command shape: combined, symmetric, FOR ALL — same rationale as
 * signature_events (950037): the model's booted() guard (see
 * app/Models/SignatureCertificate.php, confirmed already present before
 * this batch — no companion model fix needed) already makes UPDATE/
 * DELETE impossible at the Eloquent layer; RLS should still govern all
 * 4 commands via one combined policy, not a command-restricted one.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. document_hash_id (NOT NULL) -> document_hashes — no composite
 *      foreign key or trigger ties document_hashes.firm_id to this
 *      row's own firm_id; only SignatureCertificateService::generate()'s
 *      explicit 'firm_id' => $request->firm_id assignment enforces
 *      agreement today.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms row will always
 *      cascade-delete dependent signature_certificates rows regardless
 *      of which tenant's context is currently active. Expected,
 *      identical behavior to every other cascade-on-firms table already
 *      forced in this repository. (Note: restrictOnDelete() on
 *      signature_request_id means a signature_requests row can never be
 *      deleted while a certificate exists — this is the one FK in this
 *      batch that is restrict, not cascade/nullOn, so the
 *      cascade-bypass caveat above does not apply to THAT FK
 *      specifically, only to this table's own firm_id -> firms
 *      cascade.)
 *   3. This table has NO *_by_firm_user_id / actor column at all
 *      (certificates are system-generated only) — explicitly noting
 *      this table does NOT have the actor-attribution gap class the
 *      other three tables in this batch have.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'signature_certificates';

    private const POLICY = 'signature_certificates_tenant_isolation';

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
