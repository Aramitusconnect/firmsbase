<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration (NOT a seeder — does not touch database/seeders/ or
 * DatabaseSeeder.php), mirroring
 * `2026_09_08_082001_seed_integration_module_catalog_entry.php` exactly
 * (checkpoint4-design-cost-control.md §2 step 3; checkpoint4-combined-design.md
 * §1.5). Idempotently upserts exactly ONE new row into the EXISTING
 * `module_catalog` table: `module_code = 'plaid'`.
 *
 * Deliberately SEPARATE from the existing `'integration'` module code —
 * Plaid is a genuinely add-on/paid-tier entitlement, distinct from the
 * base Microsoft/Google `'integration'` entitlement
 * (`App\Services\PlaidEntitlementPolicyService::MODULE_CODE`). A
 * `plan_modules` row with `module_code = 'plaid', is_addon = true` is
 * how a commercial package includes it (`PlanModule.is_addon`'s
 * existing convention) — not built here, a later commercial-catalog
 * concern.
 *
 * DISTINCT from `..._seed_plaid_integration_provider_catalog_entry.php`
 * (the Plaid-provider-core track's own migration, out of this
 * checkpoint's scope for this writer): that migration seeds a row in
 * the `integration_providers` catalog (`code => 'plaid'`); this
 * migration seeds a row in the `module_catalog` table
 * (`module_code => 'plaid'`), gating the separate, paid-add-on Plaid
 * ENTITLEMENT — checkpoint4-combined-design.md §1.5 disambiguates the
 * two explicitly, since a skimming reviewer could otherwise mistake
 * them for duplicates.
 */
return new class extends Migration
{
    private array $modules = [
        ['module_code' => 'plaid', 'module_name' => 'Plaid (Financial Evidence)'],
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
