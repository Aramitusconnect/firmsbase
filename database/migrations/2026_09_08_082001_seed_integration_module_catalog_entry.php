<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration (NOT a seeder — does not touch database/seeders/ or
 * DatabaseSeeder.php), mirroring the EXISTING
 * `2026_07_21_900006_seed_phase14_module_catalog_webhook_entry.php`
 * pattern exactly (Checkpoint 9, frozen-design-post-security-review.md
 * §5; agent-9h-architecture-security-review.md §4). Idempotently
 * upserts exactly ONE new row into the EXISTING module_catalog table:
 * module_code 'integration'.
 *
 * This is a data seed into an existing table, not a new entitlement
 * system and not a new table. 'integration' is deliberately kept
 * SEPARATE from the existing 'webhook'/'api'/'ai' module codes — a firm
 * can have integration access without webhook access, or vice versa,
 * matching this codebase's established "one module_code per
 * independently-entitleable feature" convention.
 *
 * No `database/seeders/*.php` file anywhere in this repository carries
 * the 'webhook' or 'ai' module_catalog rows either (both were seeded
 * exclusively via their own migrations, `2026_07_21_900006_...` and
 * `2026_07_23_900009_...`) — there is no existing seeder file to add an
 * 'integration' row to; this migration is the sole, correct vehicle,
 * matching precedent exactly.
 *
 * Upsert keyed on module_code means re-running this migration (e.g. in
 * a fresh test database on every run) never creates a duplicate row and
 * never errors on a second run.
 */
return new class extends Migration
{
    private array $modules = [
        ['module_code' => 'integration', 'module_name' => 'Integrations'],
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
