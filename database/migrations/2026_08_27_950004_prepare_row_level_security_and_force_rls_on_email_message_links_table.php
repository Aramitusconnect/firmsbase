<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * email_message_links checkpoint — activates FORCE ROW LEVEL SECURITY
 * for the email-to-client/matter association table. Mirrors
 * database/migrations/2026_08_27_950003_prepare_row_level_security_and_force_rls_on_firm_ai_settings_table.php
 * exactly.
 *
 * email_message_links has NO pre-existing RLS policy — it is listed
 * under RowLevelSecurityCoverageMappingService::missingPreparedTables(),
 * so this migration does all three required steps in one batch: ENABLE
 * ROW LEVEL SECURITY, CREATE POLICY, and FORCE ROW LEVEL SECURITY,
 * never leaving RLS-enabled-with-no-policy as an intermediate state.
 *
 * Table selection rationale: email_message_links.firm_id is a direct,
 * NOT NULL foreign key to firms
 * (foreignId('firm_id')->constrained('firms')->cascadeOnDelete(), see
 * database/migrations/2026_07_12_900004_create_email_message_links_table.php).
 * This table's parent, email_messages, remains unprepared (still in
 * missingPreparedTables()) — that is independently safe: RLS is
 * enforced per-table, and email_message_links carries its own direct
 * firm_id column rather than deriving tenancy transitively through
 * email_message_id, so activating it does not require email_messages
 * to be prepared first.
 *
 * Known deferred, non-blocking gap: nothing at the database layer ties
 * this row's firm_id to the actual firm_id of the rows referenced by
 * email_message_id, matter_id, client_id, or linked_by_firm_user_id —
 * there is no composite foreign key or trigger enforcing that
 * consistency. Today that consistency is enforced only by
 * EmailMessageLinkingService::link()'s existing inline PHP checks
 * (matter/client/actor firm_id must match the message's firm_id)
 * before the row is ever created. This FORCE activation does not
 * close that transitive gap — it only guarantees that, whatever
 * firm_id ends up on a row, cross-firm reads/writes against that row
 * are denied. This residual gap is intentionally not hidden.
 *
 * Policy shape: a single policy with an EXPLICIT WITH CHECK clause
 * (identical to the USING expression), matching the firm_ai_settings
 * checkpoint's own deliberate, reviewed choice.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'email_message_links';

    private const POLICY = 'email_message_links_tenant_isolation';

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
     * Full rollback: this migration introduced the policy itself (no
     * prior preparation migration existed for email_message_links), so
     * down() must remove all three effects it added: FORCE, the
     * policy, and RLS being enabled at all — restoring the table to
     * its true pre-this-migration (MISSING_PREPARED_TABLES) state.
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
