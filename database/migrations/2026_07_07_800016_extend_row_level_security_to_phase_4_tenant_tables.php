<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends Phase 1E's RLS preparation pattern (ENABLE ROW LEVEL
 * SECURITY + a firm_id policy, deliberately WITHOUT FORCE ROW LEVEL
 * SECURITY) to the 11 Phase 4 tables that carry their own firm_id
 * column, including notification_templates despite its nullable
 * firm_id — same treatment as Phase 2's timeline_events, using
 * NULLIF() so a NULL firm_id (a global default template) is simply
 * never matched by an active tenant context, never a query error.
 *
 * document_versions, document_request_items, and task_dependencies are
 * excluded — no firm_id of their own, scoped transitively through
 * their parent. readiness_scorecard_components is excluded — a global
 * platform catalog, same as practice_areas/matter_types.
 */
return new class extends Migration
{
    private array $tables = [
        'documents',
        'document_requests',
        'tasks',
        'deadlines',
        'calendar_events',
        'notification_events',
        'notification_templates',
        'document_chase_rules',
        'document_chase_events',
        'matter_readiness_scores',
        'readiness_score_events',
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
