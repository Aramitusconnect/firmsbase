<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * email_accounts — first of a four-table, one-batch FORCE ROW LEVEL
 * SECURITY activation covering the email domain (Section 39A-5 Wave
 * 5): email_accounts (this migration), email_messages (2026_08_27_
 * 950026), email_attachments (2026_08_27_950027), and
 * email_sync_events (2026_08_27_950028). All four land together as one
 * atomic batch, not four independently-deployable checkpoints —
 * EmailSyncService::sync() writes to multiple of these tables in one
 * un-transacted PHP call today, so a partial rollout would be
 * policy-safe per table but caller-unsafe across the group. The shared
 * registry (RowLevelSecurityCoverageMappingService, still listing all
 * four under MISSING_PREPARED_TABLES at the point this migration lands
 * on its own) is updated once by the coordinator in a later, separate
 * wave-integration commit — not by this migration.
 *
 * Like matter_expenses/ai_approval_events before it, this table has NO
 * pre-existing policy to flip FORCE on for — no ENABLE ROW LEVEL
 * SECURITY and no CREATE POLICY exist for it anywhere yet. This
 * migration does all three steps required by
 * docs/governance/future-table-requirements.md #4/#5 in one batch:
 * ENABLE ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL
 * SECURITY — never leaving RLS-enabled-with-no-policy as an
 * intermediate state.
 *
 * Table selection rationale: email_accounts is the root of the email
 * domain — it carries a direct, NOT NULL firm_id column
 * (foreignId('firm_id')->constrained('firms')->cascadeOnDelete(), see
 * database/migrations/2026_07_12_900001_create_email_accounts_table.php)
 * and has no FK of its own into any of the other three tables in this
 * batch. email_messages.email_account_id, email_sync_events.
 * email_account_id (nullable), email_oauth_tokens.email_account_id
 * (out of scope, InheritedTenant), and email_visibility_rules.
 * email_account_id (out of scope, already FORCE-enforced since Wave 2)
 * all reference it, not the reverse.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the explicit-over-
 * implicit convention established since customer_success_health_scores.
 *
 * Known, deliberately-deferred gaps (not closed by this migration):
 *   1. No composite foreign key or trigger ties any of this batch's
 *      four tables' firm_id to the ACTUAL firm_id of the parent row its
 *      own belongs-to FK points at. This migration does not close that
 *      transitive gap for email_accounts' own children — it only
 *      guarantees that, whatever firm_id ends up on an email_accounts
 *      row, cross-firm reads/writes against that row are denied.
 *   2. PostgreSQL's documented row-security semantics exempt foreign-
 *      key ON DELETE CASCADE actions from row-security policy
 *      evaluation entirely — deleting a firms or firm_users row will
 *      always cascade-delete dependent email_accounts rows regardless
 *      of which tenant's context is currently active. Expected,
 *      identical behavior to every other cascade-on-firms table already
 *      forced in this repository.
 *   3. connected_by_firm_user_id is an actor FK, not an ownership FK —
 *      a hard delete of a FirmUser row (no SoftDeletes on FirmUser)
 *      would cascade through the entire email domain. No current caller
 *      deletes FirmUser rows; documented as a deliberately-deferred,
 *      pre-existing structural gap, not something this activation
 *      creates or should attempt to close.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'email_accounts';

    private const POLICY = 'email_accounts_tenant_isolation';

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
