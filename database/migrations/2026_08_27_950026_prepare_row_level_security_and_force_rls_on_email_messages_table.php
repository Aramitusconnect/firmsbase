<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * email_messages — second of the four-table, one-batch FORCE ROW LEVEL
 * SECURITY activation covering the email domain (Section 39A-5 Wave
 * 5): email_accounts (2026_08_27_950025), email_messages (this
 * migration), email_attachments (2026_08_27_950027), and
 * email_sync_events (2026_08_27_950028). All four land together as one
 * atomic batch — see 950025's docblock for the full batch rationale.
 *
 * Like email_accounts before it in this same batch, email_messages has
 * NO pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: email_messages carries a direct, NOT NULL
 * firm_id column AND a NOT NULL email_account_id foreign key to
 * email_accounts, both cascadeOnDelete() (see database/migrations/
 * 2026_07_12_900003_create_email_messages_table.php:37-38). The policy
 * predicate below reads firm_id directly — it does not need to look
 * through email_account_id to find a firm. encryption_key_id is
 * nullable and restrictOnDelete() into tenant_encryption_keys (already
 * FORCE RLS'd since Phase 1) — that is not the ownership boundary for
 * this table and is unaffected by this migration.
 *
 * No UPDATE method exists anywhere in app/ for this table today (only
 * EmailSyncService::captureMessage()'s create()) — per the
 * expense_receipts checkpoint's own established reasoning, narrowing
 * the DB policy to deny UPDATE would be an unrequested guarantee, so
 * the standard, symmetric four-command shape is used here as well.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-
 * implicit convention established since customer_success_health_scores.
 *
 * Known, deliberately-deferred gaps (not closed by this migration,
 * identical in kind to email_accounts' own — see 950025):
 *   1. No composite foreign key or trigger ties email_messages.firm_id
 *      to the ACTUAL firm_id of the email_accounts row email_account_id
 *      points at. Today the only thing tying a captured message's
 *      firm_id to its account's real firm is
 *      EmailSyncService::captureMessage()'s own explicit
 *      'firm_id' => $firm->id assignment (where $firm is
 *      $account->firm) — a raw insert bypassing that service is not
 *      caught by anything except this row's own firm_id predicate. This
 *      migration does not close that transitive gap.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms or email_accounts row
 *      will always cascade-delete dependent email_messages rows
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
    private const TABLE = 'email_messages';

    private const POLICY = 'email_messages_tenant_isolation';

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
