<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * offboarding_requests — sixth and last of Wave 9's six-table batch
 * (see 2026_08_29_970001's docblock for the full batch list and
 * ordering rationale).
 *
 * offboarding_requests has NO pre-existing policy — this migration
 * does all three steps (ENABLE, CREATE POLICY, FORCE) in one batch, per
 * docs/governance/future-table-requirements.md #4/#5.
 *
 * Table selection rationale: offboarding_requests carries a direct,
 * NOT NULL firm_id column, cascadeOnDelete(). The OffboardingRequest
 * model uses BelongsToTenant + HasPublicUuid — a genuine tenant-owned
 * row: the firm-level state machine sequencing export -> retention
 * clearance -> legal-hold clearance -> ready-for-deletion -> completed.
 * requested_by_platform_admin_id is NOT NULL but platform-global
 * (platform-admin-initiated by construction) — not a tenant-owned
 * value needing cross-firm validation.
 *
 * REQUIRED co-landed service change: OffboardingRequestService::
 * request() wraps its create() call; complete()/cancel() each wrap
 * their entire body through the trailing ->fresh(); advance() wraps
 * its entire body (including its call to evaluateReadiness()) in a NEW
 * runWithFirmContext($request->firm_id, ...) call. This new wrap
 * intentionally NESTS around evaluateReadiness()'s EXISTING inner wrap
 * (added in Wave 8 to fix the legal_holds fail-open bug, at the same
 * firm) — confirmed structurally safe per TenantContextService's
 * snapshot/restore-in-finally semantics, and left unmodified by this
 * migration's commit.
 *
 * Required, but NOT this phase's job (flagged for a dedicated
 * test-focused phase): tests/Feature/Security/RlsForceRollout/
 * LegalHoldsForceRlsActivationTest.php lines 486, 492, and 534 each
 * call $offboardingRequest->fresh() as a bare function argument to
 * evaluateReadiness()/advance() from OUTSIDE any tenant context (the
 * create()-time wrap has already exited and restored to none by that
 * point in the test) — once this migration lands, that bare ->fresh()
 * will return null and passing null into a non-nullable parameter will
 * throw a TypeError. This must be fixed in the same logical change as
 * this migration's activation, but is explicitly out of this
 * implementation phase's file-editing authority (test files are a
 * separate phase's remit) — not fixed here, only flagged.
 *
 * Known, out-of-scope-for-this-wave item (no action needed):
 *   offboarding_exports (a different table, no firm_id at all) is read
 *   by evaluateReadiness() — flagged for awareness only.
 *
 *   Standard cascade-bypasses-RLS caveat applies here as it does to
 *   every cascade-on-firms table already forced in this repository.
 *
 * The table name is a single hardcoded string literal (never user
 * input), but is still validated against a strict identifier pattern
 * before being interpolated into SQL and is double-quoted, matching
 * every prior activation migration's own defensive pattern.
 */
return new class extends Migration
{
    private const TABLE = 'offboarding_requests';

    private const POLICY = 'offboarding_requests_tenant_isolation';

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
     * Full rollback: this migration introduced the policy itself, so
     * down() must remove all three effects up() added: FORCE, the
     * policy, and row-level security being enabled at all.
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
