<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Section 39A-3L Phase B6 — permanently activates FORCE ROW LEVEL
 * SECURITY for security_events, the eighth and FINAL of this arc's
 * nullable-firm_id checkpoints, deliberately saved for last. Full
 * design dossier: rls-checkpoints/39a3l/B6-security_events-design-
 * dossier.md (APPROVED, GO WITH CHANGES from both rls-policy-designer
 * and tenant-context-auditor, all required changes incorporated and
 * independently re-verified).
 *
 * The highest production-blast-radius checkpoint in the whole mission:
 * two of this table's four write call sites fire synchronously inside
 * Laravel's own authentication flow (AppServiceProvider's Login/Failed
 * guard-event listeners, both the web and platform_admin guards). The
 * prerequisite application-code fixes (already committed separately,
 * matching this arc's established two-commit pattern) include a real,
 * independent, pre-existing bug fix: the Login listener's firm_id
 * resolution previously always returned NULL under firm_users' own
 * FORCE RLS, regardless of real membership — fixed by routing through
 * the existing User::activeFirmUser() bootstrap helper.
 *
 * Unlike every prior checkpoint in this arc, this table needed a THIRD
 * distinct nullable-firm_id design, different from both the six "easy"
 * tables (visible-to-everyone was safe) and timeline_events (fail-
 * closed-to-everyone was safe, proven via live FK-cascade-bypasses-RLS
 * experiments): live, tested, production code legitimately writes
 * null-firm_id rows whose content is genuinely cross-tenant-sensitive
 * (failed-login attempted emails/IPs, platform-admin login history,
 * high-risk-change approval metadata). Both the READ policy and the
 * WRITE policy change here — a first for this arc's tables with no
 * pre-existing leak-vector concern on the read side alone.
 *
 * Design: null rows are visible ONLY when no tenant context is active
 * (never when some OTHER firm's context is active) — a deliberate
 * middle ground between "fail closed to everyone" (timeline_events)
 * and "visible to every tenant regardless of context" (the six easy
 * tables). A firm-scoped session may only read/write its own firm's
 * rows or (when no context is active at all) the platform-wide rows; a
 * context-free session may read/write only the platform-wide rows,
 * never any firm's private events. No context state ever grants
 * visibility into another firm's rows.
 *
 * The write policy is deliberately FOR INSERT only (not FOR ALL) —
 * narrower than every other write policy in this Phase B6 category,
 * since security_events is genuinely append-only with no live
 * UPDATE/DELETE call site anywhere. NOTE: under FORCE RLS with only
 * FOR SELECT/FOR INSERT policies, a stray UPDATE/DELETE is NOT
 * rejected by Postgres with an exception — it is a silent 0-row
 * no-op (empirically confirmed during design review). Real protection
 * against a stray mutation comes from a NEW app-layer guard added to
 * SecurityEvent::booted() in this same preparation commit (mirroring
 * TrustLedgerEntry::booted() exactly), not from the RLS shape alone.
 *
 * Application-code prerequisites (already committed separately):
 * AppServiceProvider's Login/Failed listeners (DB-session-only context,
 * not runWithFirmContext(), to avoid interacting with Laravel's own
 * auth-flow transaction/session state; also fixes a real, independent,
 * pre-existing bug — see below), SupportAccessPolicyService::
 * logNotification()/logSessionAudit() (self-wrapped, no real caller
 * exists today), WebhookReplayService::auditSecurityEvent()'s call
 * site (the one deliberately deferred from the timeline_events
 * checkpoint), HighRiskPlatformChangePolicyService::audit() (a
 * runWithoutFirmContext() wrap — confirmed LIVE, not merely defensive,
 * by TrustModeActivationServiceTest: its FirmUser factory call leaves
 * DB-session context set to that firm, and an unwrapped null-firm_id
 * insert moments later would fail its WITH CHECK outright),
 * SecurityEventFactory (standard context-hold create() override,
 * transplanted from BackupRestoreTestFactory), and SecurityEvent's new
 * append-only booted() guard.
 *
 * Known gap NOT fixed in this batch (disclosed, not hidden):
 * HighRiskPlatformChangePolicyService's state-write/audit-write
 * non-atomicity (its 5 public methods write HighRiskChangeRequest and
 * then call audit() as two separate, non-transactional operations) —
 * pre-existing, not a regression introduced by this checkpoint, and
 * deliberately left unfixed here (narrower blast radius than
 * KeyDestructionExecutionService's own irreversible-action fix in the
 * timeline_events checkpoint, since a lost audit row here is for a
 * still-reversible/still-queryable state transition, not an
 * irreversible one).
 *
 * down() restores the ORIGINAL single-expression policy byte-for-byte
 * (quoted directly from 2026_07_04_500001_prepare_row_level_security_
 * for_tenant_tables.php) and drops both new policies.
 */
return new class extends Migration
{
    private const TABLE = 'security_events';

    private const ORIGINAL_POLICY = 'security_events_tenant_isolation';

    private const WRITE_POLICY = 'security_events_platform_write';

    public function up(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);

        DB::statement("DROP POLICY {$this->quoteIdentifier(self::ORIGINAL_POLICY)} ON {$table}");

        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::ORIGINAL_POLICY)}
            ON {$table}
            FOR SELECT
            USING (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
                OR (firm_id IS NULL AND NULLIF(current_setting('app.current_firm_id', true), '')::bigint IS NULL)
            )
        SQL);

        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::WRITE_POLICY)}
            ON {$table}
            FOR INSERT
            WITH CHECK (
                firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
                OR (firm_id IS NULL AND NULLIF(current_setting('app.current_firm_id', true), '')::bigint IS NULL)
            )
        SQL);

        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    public function down(): void
    {
        $table = $this->quoteIdentifier(self::TABLE);

        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");

        DB::statement("DROP POLICY {$this->quoteIdentifier(self::WRITE_POLICY)} ON {$table}");
        DB::statement("DROP POLICY {$this->quoteIdentifier(self::ORIGINAL_POLICY)} ON {$table}");

        // Byte-for-byte restoration of the original Phase 1 preparation
        // policy text — no IS NULL branch, no separate WITH CHECK.
        DB::statement(<<<SQL
            CREATE POLICY {$this->quoteIdentifier(self::ORIGINAL_POLICY)} ON {$table}
            USING (firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint)
        SQL);
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $identifier)) {
            throw new \RuntimeException("Refusing to operate on an unsafe/unexpected identifier: {$identifier}");
        }

        return '"'.$identifier.'"';
    }
};
