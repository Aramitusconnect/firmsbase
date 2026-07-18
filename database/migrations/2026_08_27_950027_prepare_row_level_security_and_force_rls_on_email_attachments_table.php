<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * email_attachments — third of the four-table, one-batch FORCE ROW
 * LEVEL SECURITY activation covering the email domain (Section 39A-5
 * Wave 5): email_accounts (2026_08_27_950025), email_messages
 * (2026_08_27_950026), email_attachments (this migration), and
 * email_sync_events (2026_08_27_950028). All four land together as one
 * atomic batch — see 950025's docblock for the full batch rationale.
 *
 * Like email_accounts/email_messages before it in this same batch,
 * email_attachments has NO pre-existing policy to flip FORCE on for —
 * no ENABLE ROW LEVEL SECURITY and no CREATE POLICY exist for it
 * anywhere yet. This migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: email_attachments carries a direct, NOT
 * NULL firm_id column AND a NOT NULL email_message_id foreign key to
 * email_messages, both cascadeOnDelete() (see database/migrations/
 * 2026_07_12_900005_create_email_attachments_table.php:29-30). The
 * policy predicate below reads firm_id directly. document_id is
 * nullable and nullOnDelete() into documents (the promotion target, out
 * of scope for this batch) — that is not the ownership boundary for
 * this table.
 *
 * A cross-firm guard already exists at the service layer as
 * defense-in-depth for the one place this table's rows become Document
 * rows: EmailAttachmentPromotionService::scanAndPromote()'s explicit
 * `if ($document->firm_id !== $firmId) throw` check, where $firmId is
 * derived via the two-hop $attachment->emailMessage->emailAccount->
 * firm_id path (never from provider-supplied data) — unchanged by this
 * migration, which only adds the database-layer guarantee alongside it.
 * scan_status/promotion_status/document_id/blocked_reason UPDATEs from
 * that service are legitimate under the standard, symmetric
 * four-command policy shape used here.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-
 * implicit convention established since customer_success_health_scores.
 *
 * Known, deliberately-deferred gaps (not closed by this migration,
 * identical in kind to email_accounts'/email_messages' own — see
 * 950025/950026):
 *   1. No composite foreign key or trigger ties email_attachments.
 *      firm_id to the ACTUAL firm_id of the email_messages row
 *      email_message_id points at. Today the only thing tying a
 *      captured attachment's firm_id to its message's real firm is
 *      EmailSyncService::captureMessage()'s own explicit
 *      'firm_id' => $firm->id assignment inside its attachment-creation
 *      loop — a raw insert bypassing that service is not caught by
 *      anything except this row's own firm_id predicate. This migration
 *      does not close that transitive gap.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms or email_messages row
 *      will always cascade-delete dependent email_attachments rows
 *      regardless of which tenant's context is currently active.
 *      Expected, identical behavior to every other cascade-on-firms
 *      table already forced in this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'email_attachments';

    private const POLICY = 'email_attachments_tenant_isolation';

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
