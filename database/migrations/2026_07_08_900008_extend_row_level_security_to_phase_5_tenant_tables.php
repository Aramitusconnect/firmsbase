<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends Phase 1E's RLS preparation pattern (ENABLE ROW LEVEL
 * SECURITY + a firm_id policy using NULLIF(), deliberately WITHOUT
 * FORCE ROW LEVEL SECURITY) to the 6 Phase 5 tables that carry their
 * own firm_id column, whether required (firm_activation_events) or
 * nullable (health_checks, backup_restore_tests, incident_events,
 * maintenance_windows, pilot_feedback_items) — a NULL firm_id (a
 * platform-wide row) is simply never matched by an active tenant
 * context, never a query error, same treatment as Phase 4's
 * notification_templates.
 *
 * status_page_events is excluded — it has no firm_id of its own, a
 * deliberate platform-level table (same treatment as Phase 4's
 * readiness_scorecard_components).
 */
return new class extends Migration
{
    private array $tables = [
        'firm_activation_events',
        'health_checks',
        'backup_restore_tests',
        'incident_events',
        'maintenance_windows',
        'pilot_feedback_items',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (firm_id = NULLIF(current_setting('app.current_firm_id', true), '')::bigint)
            SQL);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
