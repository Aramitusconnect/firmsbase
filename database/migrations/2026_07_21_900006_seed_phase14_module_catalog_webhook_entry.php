<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration (NOT a seeder — does not touch database/seeders/ or
 * DatabaseSeeder.php), mirroring the EXISTING Phase 6
 * seed_phase6_module_catalog_entries.php pattern exactly. Idempotently
 * upserts exactly ONE new row into the EXISTING module_catalog table
 * (Phase 1): module_code 'webhook' (approved correction #2).
 *
 * This is a data seed into an existing table, not a new entitlement
 * system and not a new table. Webhooks and the existing 'api' module
 * code are deliberately kept SEPARATE — a firm can have API access
 * without webhook access, or vice versa (approved correction #2: "Do
 * not reuse api for webhook entitlement. Webhooks and API must be
 * separately entitleable.").
 *
 * Upsert keyed on module_code means re-running this migration (e.g. in
 * a fresh test database on every run) never creates a duplicate row
 * and never errors on a second run — required test: "webhook
 * module_catalog seed is idempotent."
 */
return new class extends Migration
{
    private array $modules = [
        ['module_code' => 'webhook', 'module_name' => 'Webhooks'],
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
