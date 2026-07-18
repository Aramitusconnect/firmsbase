<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * email_sync_events — fourth and last of the four-table, one-batch
 * FORCE ROW LEVEL SECURITY activation covering the email domain
 * (Section 39A-5 Wave 5): email_accounts (2026_08_27_950025),
 * email_messages (2026_08_27_950026), email_attachments (2026_08_27_
 * 950027), and email_sync_events (this migration). All four land
 * together as one atomic batch — see 950025's docblock for the full
 * batch rationale. Placed last because this table's own foreign key
 * into email_accounts is nullable (no hard ordering dependency), and
 * because it converges audit writes from every other writer path in
 * the domain (EmailAccountService, EmailSyncService,
 * EmailAttachmentPromotionService, EmailOAuthTokenService all call
 * EmailSyncAuditService::record(), the sole writer of this table).
 *
 * Like the other three tables in this batch, email_sync_events has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: email_sync_events carries a direct, NOT
 * NULL firm_id column, cascadeOnDelete() (see database/migrations/
 * 2026_07_12_900006_create_email_sync_events_table.php:28). The
 * EmailSyncEvent model deliberately does NOT use BelongsToTenant (Phase
 * 8 ImportAuditEvent precedent — audit tables are queried explicitly by
 * services, not globally scoped), so unlike every other table in this
 * batch, this table has NO application-layer global-scope backstop at
 * all: once FORCE is active here, tenant isolation depends entirely on
 * this policy. A missed tenant-context wrap at a write/read call site
 * fails silently (empty result set on read, RLS rejection on write) —
 * this is the intended fail-closed behavior, not a defect.
 *
 * REQUIRED companion fix landing in this same commit (not optional, not
 * deferred): App\Models\EmailSyncEvent gained a booted() guard,
 * mirroring App\Models\AiApprovalEvent's own, throwing LogicException on
 * any Eloquent update/delete of an existing row. This table is
 * append-only by convention (EmailSyncAuditService exposes only
 * record() and the read-only latestCursorFor() — zero update/delete
 * call sites exist anywhere in app/) but, unlike ai_approval_events
 * (which additionally has BelongsToTenant as a second line of defense),
 * email_sync_events had no append-only guard of any kind before this
 * change. As with ai_approval_events, this migration's WITH CHECK
 * clause governs INSERT-time firm ownership only — it is NOT the
 * append-only mechanism, and does not by itself prevent an UPDATE or
 * DELETE against a row the active session's firm context legitimately
 * owns. That guarantee comes exclusively from the model-layer booted()
 * guard, not from RLS.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-
 * implicit convention established since customer_success_health_scores
 * — not a FOR INSERT-only clause, mirroring ai_approval_events' own
 * resolved precedent exactly.
 *
 * Known, deliberately-deferred gaps (not closed by this migration,
 * identical in kind to the other three tables in this batch):
 *   1. No composite foreign key or trigger ties email_sync_events.
 *      firm_id to the ACTUAL firm_id of the email_accounts row
 *      email_account_id (nullable) points at, when present. Today the
 *      only thing tying an audit row's firm_id to the correct firm is
 *      EmailSyncAuditService::record()'s own explicit
 *      'firm_id' => $firm->id assignment. This migration does not close
 *      that transitive gap.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms or email_accounts row
 *      will always cascade-delete dependent email_sync_events rows
 *      regardless of which tenant's context is currently active.
 *      Expected, identical behavior to every other cascade-on-firms
 *      table already forced in this repository, and orthogonal to the
 *      append-only guarantee above (a cascade delete is not an Eloquent
 *      update/delete of an existing row through the model).
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'email_sync_events';

    private const POLICY = 'email_sync_events_tenant_isolation';

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
     * touch EmailSyncEvent::booted()'s append-only guard, which is a
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
