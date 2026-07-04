<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration (NOT a seeder — does not touch database/seeders/ or
 * DatabaseSeeder.php) that idempotently upserts the 17 named plan
 * control module_code rows into the EXISTING module_catalog table
 * (Phase 1). Uses upsert keyed on module_code, so re-running this
 * migration (e.g. in a fresh test database on every run) never creates
 * duplicates and never errors on a second run.
 *
 * support_access_level is deliberately EXCLUDED from this list — it is
 * a plan SETTING (plans.support_access_level), not an enable/disable
 * module, per the approved decision.
 */
return new class extends Migration
{
    private array $modules = [
        ['module_code' => 'ai', 'module_name' => 'AI'],
        ['module_code' => 'expenses', 'module_name' => 'Expenses'],
        ['module_code' => 'trust_iolta', 'module_name' => 'Trust/IOLTA'],
        ['module_code' => 'time_tracking', 'module_name' => 'Time Tracking'],
        ['module_code' => 'invoices', 'module_name' => 'Invoices'],
        ['module_code' => 'payments', 'module_name' => 'Payments'],
        ['module_code' => 'payment_plans', 'module_name' => 'Payment Plans'],
        ['module_code' => 'client_intake_crm', 'module_name' => 'Client Intake CRM'],
        ['module_code' => 'document_generation', 'module_name' => 'Document Generation'],
        ['module_code' => 'forms', 'module_name' => 'Forms'],
        ['module_code' => 'e_signature', 'module_name' => 'E-Signature'],
        ['module_code' => 'email', 'module_name' => 'Email'],
        ['module_code' => 'client_portal', 'module_name' => 'Client Portal'],
        ['module_code' => 'reports', 'module_name' => 'Reports'],
        ['module_code' => 'api', 'module_name' => 'API'],
        ['module_code' => 'dedicated_branding', 'module_name' => 'Dedicated Branding'],
        ['module_code' => 'practice_area_templates', 'module_name' => 'Practice Area Templates'],
    ];

    public function up(): void
    {
        $now = now();

        $rows = array_map(
            fn (array $module) => array_merge($module, [
                'category' => 'plan_control',
                'is_active' => true,
                'requires_admin_approval' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $this->modules
        );

        DB::table('module_catalog')->upsert(
            $rows,
            ['module_code'],
            ['module_name', 'category', 'is_active', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('module_catalog')
            ->whereIn('module_code', array_column($this->modules, 'module_code'))
            ->delete();
    }
};
