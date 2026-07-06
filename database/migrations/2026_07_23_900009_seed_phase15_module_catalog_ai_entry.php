<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration (NOT a seeder — does not touch database/seeders/ or
 * DatabaseSeeder.php), mirroring the EXISTING Phase 14
 * seed_phase14_module_catalog_webhook_entry.php pattern exactly.
 * Idempotently upserts exactly ONE new row into the EXISTING
 * module_catalog table (Phase 1): module_code 'ai'.
 *
 * requires_admin_approval is true — AI is treated the same as any
 * other high-sensitivity module: enabling it for a firm is not a
 * self-serve plan toggle alone.
 *
 * Upsert keyed on module_code means re-running this migration (e.g. in
 * a fresh test database on every run) never creates a duplicate row
 * and never errors on a second run.
 */
return new class extends Migration
{
    private array $modules = [
        ['module_code' => 'ai', 'module_name' => 'AI Governance and Firm-Owned AI Keys'],
    ];

    public function up(): void
    {
        $now = now();

        $rows = array_map(
            fn (array $module) => array_merge($module, [
                'category' => 'plan_control',
                'is_active' => true,
                'requires_admin_approval' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $this->modules
        );

        DB::table('module_catalog')->upsert(
            $rows,
            ['module_code'],
            ['module_name', 'category', 'is_active', 'requires_admin_approval', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('module_catalog')
            ->whereIn('module_code', array_column($this->modules, 'module_code'))
            ->delete();
    }
};
