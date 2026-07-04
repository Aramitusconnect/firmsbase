<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends Phase 1E's RLS preparation pattern (ENABLE ROW LEVEL
 * SECURITY + a firm_id policy, deliberately WITHOUT FORCE ROW LEVEL
 * SECURITY) to the 8 Phase 3 tables that carry their own firm_id
 * column. Same as Phase 1E/Phase 2: inert today (table-owner role is
 * exempt from non-forced RLS) until a dedicated future "RLS
 * Enforcement Activation" gate lands session-variable middleware and
 * FORCE together.
 *
 * invoice_lines, payment_plan_installments, and manual_payment_records
 * are deliberately excluded — they have no firm_id of their own,
 * tenant isolation flows through their parent (invoice_id /
 * payment_plan_id / payment_id), same as matter_parties in Phase 2.
 */
return new class extends Migration
{
    private array $tables = [
        'employee_rates',
        'time_tracking_sessions',
        'time_entries',
        'invoices',
        'payment_plans',
        'payments',
        'payment_plan_events',
        'payment_classification_events',
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
