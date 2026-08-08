<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the global `practice_areas` catalog with an initial broad US
 * law-practice list (approved product decision — see the mission that
 * introduced this migration). Idempotent DB::table()->upsert() data
 * migration, matching the established pattern already used for
 * `module_catalog` (e.g. 2026_07_09_900023_seed_phase6_module_catalog_
 * entries.php): keyed on the natural unique key (`code`), re-runnable,
 * never truncates. Platform Admin CRUD (PracticeAreaResource) can add
 * further entries or deactivate any of these later — this migration
 * only guarantees a real starting catalog exists.
 */
return new class extends Migration
{
    private array $practiceAreas = [
        ['code' => 'administrative_law', 'name' => 'Administrative Law'],
        ['code' => 'appellate_law', 'name' => 'Appellate Law'],
        ['code' => 'bankruptcy', 'name' => 'Bankruptcy'],
        ['code' => 'business_corporate_law', 'name' => 'Business / Corporate Law'],
        ['code' => 'civil_litigation', 'name' => 'Civil Litigation'],
        ['code' => 'civil_rights', 'name' => 'Civil Rights'],
        ['code' => 'consumer_law', 'name' => 'Consumer Law'],
        ['code' => 'criminal_defense', 'name' => 'Criminal Defense'],
        ['code' => 'employment_labor_law', 'name' => 'Employment / Labor Law'],
        ['code' => 'estate_planning', 'name' => 'Estate Planning'],
        ['code' => 'family_law', 'name' => 'Family Law'],
        ['code' => 'immigration_law', 'name' => 'Immigration Law'],
        ['code' => 'insurance_law', 'name' => 'Insurance Law'],
        ['code' => 'intellectual_property', 'name' => 'Intellectual Property'],
        ['code' => 'personal_injury', 'name' => 'Personal Injury'],
        ['code' => 'probate_estate_administration', 'name' => 'Probate / Estate Administration'],
        ['code' => 'real_estate_law', 'name' => 'Real Estate Law'],
        ['code' => 'tax_law', 'name' => 'Tax Law'],
        ['code' => 'traffic_dui', 'name' => 'Traffic / DUI'],
        ['code' => 'workers_compensation', 'name' => "Workers' Compensation"],
        ['code' => 'other', 'name' => 'Other'],
    ];

    public function up(): void
    {
        $now = now();

        $rows = array_map(
            fn (array $area) => array_merge($area, [
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $this->practiceAreas
        );

        DB::table('practice_areas')->upsert(
            $rows,
            ['code'],
            ['name', 'is_active', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('practice_areas')
            ->whereIn('code', array_column($this->practiceAreas, 'code'))
            ->delete();
    }
};
