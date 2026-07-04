<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the Phase 1 RLS PREPARATION pattern to every Phase 2 table
 * that has a direct firm_id column. Same non-forcing design as the
 * Phase 1 migration (2026_07_04_500001_prepare_row_level_security_for_tenant_tables.php)
 * — ENABLE ROW LEVEL SECURITY + a firm_id-matching policy, no FORCE.
 * See that migration's doc comment for the full reasoning (table-owner
 * exemption, no session-variable middleware yet, the "Phase 1 RLS
 * Enforcement Activation" follow-up gate that owns turning enforcement
 * on for both Phase 1 and Phase 2 tables together).
 *
 * Tables covered (have a direct firm_id column): lead_sources,
 * consultation_outcomes, clients, firm_leads, consultations, contacts,
 * parties, matters, firm_practice_areas, installed_template_packs,
 * intake_submissions, conflict_check_runs, timeline_events.
 *
 * Deliberately excluded: practice_areas, matter_types, template_packs,
 * template_pack_versions, intake_templates (global catalogs, no
 * firm_id); matter_parties, matter_assignments, conflict_check_results
 * (no firm_id column of their own — transitively scoped through their
 * parent row, same pattern as activation_checklist_items in Phase 1).
 */
return new class extends Migration
{
    private array $tables = [
        'lead_sources',
        'consultation_outcomes',
        'clients',
        'firm_leads',
        'consultations',
        'contacts',
        'parties',
        'matters',
        'firm_practice_areas',
        'installed_template_packs',
        'intake_submissions',
        'conflict_check_runs',
        'timeline_events',
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
