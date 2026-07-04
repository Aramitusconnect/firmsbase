<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL row-level security PREPARATION — not yet enforcement.
 *
 * Enables RLS and creates a firm_id-matching policy on every table
 * that has a direct firm_id column. Deliberately does NOT run FORCE
 * ROW LEVEL SECURITY. Without FORCE, Postgres exempts the table owner
 * role from its own RLS policies, and the app's DB connection role is
 * that owner (it ran the migrations that created these tables) — so
 * these policies are inert for the app's normal connection today. This
 * is intentional, not an oversight.
 *
 * Why not enable enforcement now: no code anywhere in the app yet ever
 * calls SET LOCAL app.current_firm_id — that wiring is HTTP/job
 * middleware, explicitly deferred, since it requires touching routes/
 * and bootstrap/providers.php, both frozen files. If FORCE were
 * enabled today, with no session variable ever set,
 * current_setting('app.current_firm_id', true) would always evaluate
 * to NULL, NULL never equals any firm_id, and every query against
 * these tables — including every test in this suite and any real
 * usage — would silently return zero rows or fail outright. That is an
 * unacceptable, unreviewed breaking change to already-tested behavior.
 *
 * The follow-up gate that owns turning this on: "Phase 1 RLS
 * Enforcement Activation" — a dedicated, explicitly-approved change
 * that must land ALL of the following together: (a) HTTP/queue/console
 * middleware that calls SET LOCAL app.current_firm_id at the start of
 * every request/job/command and clears it at the end, (b) ALTER TABLE
 * ... FORCE ROW LEVEL SECURITY on all tables below, (c) a full
 * regression pass confirming every existing test still passes under
 * enforcement. This migration only prepares the policies so that
 * activation gate is a small, reversible flip rather than a
 * from-scratch schema change.
 *
 * Tables covered (must have a direct firm_id column): firm_settings,
 * firm_users, security_events, firm_licenses, firm_entitlements,
 * firm_entitlement_events, activation_checklists, tenant_encryption_keys,
 * client_communication_preferences, communication_consents,
 * communication_consent_events.
 *
 * Deliberately excluded: organizations/billing_accounts/firms (these
 * ARE the tenancy boundary, not tenant-owned data); module_catalog
 * (global reference data); users/platform_admins (identity records
 * independent of any single firm); activation_checklist_items (no
 * firm_id column — protected transitively through its parent
 * activation_checklists row).
 */
return new class extends Migration
{
    private array $tables = [
        'firm_settings',
        'firm_users',
        'security_events',
        'firm_licenses',
        'firm_entitlements',
        'firm_entitlement_events',
        'activation_checklists',
        'tenant_encryption_keys',
        'client_communication_preferences',
        'communication_consents',
        'communication_consent_events',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

            DB::statement(<<<SQL
                CREATE POLICY {$this->policyName($table)}
                ON {$table}
                USING (
                    firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint
                )
            SQL);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$this->policyName($table)} ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }

    private function policyName(string $table): string
    {
        return "{$table}_tenant_isolation";
    }
};
