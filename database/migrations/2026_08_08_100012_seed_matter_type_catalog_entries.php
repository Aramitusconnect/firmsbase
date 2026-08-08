<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the global `matter_types` catalog with sensible broad starter
 * types nested under each `practice_areas` row seeded by the previous
 * migration (2026_08_09_100001). Deliberately NOT an exhaustive,
 * jurisdiction-specific taxonomy — per the approved product decision,
 * every practice area without a detailed breakdown below gets a
 * "General Matter" + "Other <Practice Area> Matter" pair; Platform
 * Admin CRUD (nested under PracticeAreaResource) can expand any of
 * these later.
 *
 * Idempotent DB::table()->upsert() data migration, same shape as
 * 2026_08_09_100001 and module_catalog's own seed migrations — keyed on
 * the natural unique key (`practice_area_id`, `code`), re-runnable,
 * never truncates. `practice_area_id` is resolved by looking up each
 * parent's `code` against the table the previous migration guarantees
 * already exists (migrations in this repo always run in filename/
 * timestamp order).
 */
return new class extends Migration
{
    /**
     * Keyed by parent practice_area code. Each value is a list of
     * [code, name] pairs. Practice areas not listed here fall back to
     * the generic ['general_matter' => 'General Matter',
     * 'other_<area>_matter' => 'Other <Area Name> Matter'] pair applied
     * in up() below.
     */
    private array $matterTypesByPracticeArea = [
        'immigration_law' => [
            ['code' => 'family_based_immigration', 'name' => 'Family-Based Immigration'],
            ['code' => 'employment_based_immigration', 'name' => 'Employment-Based Immigration'],
            ['code' => 'adjustment_of_status', 'name' => 'Adjustment of Status'],
            ['code' => 'consular_processing', 'name' => 'Consular Processing'],
            ['code' => 'naturalization_citizenship', 'name' => 'Naturalization / Citizenship'],
            ['code' => 'removal_deportation_defense', 'name' => 'Removal / Deportation Defense'],
            ['code' => 'asylum', 'name' => 'Asylum'],
            ['code' => 'visa_matter', 'name' => 'Visa Matter'],
            ['code' => 'other_immigration_matter', 'name' => 'Other Immigration Matter'],
        ],
        'family_law' => [
            ['code' => 'divorce', 'name' => 'Divorce'],
            ['code' => 'child_custody', 'name' => 'Child Custody'],
            ['code' => 'child_support', 'name' => 'Child Support'],
            ['code' => 'spousal_support', 'name' => 'Spousal Support'],
            ['code' => 'adoption', 'name' => 'Adoption'],
            ['code' => 'prenuptial_postnuptial_agreement', 'name' => 'Prenuptial / Postnuptial Agreement'],
            ['code' => 'domestic_violence_protective_order', 'name' => 'Domestic Violence / Protective Order'],
            ['code' => 'other_family_matter', 'name' => 'Other Family Matter'],
        ],
        'criminal_defense' => [
            ['code' => 'felony', 'name' => 'Felony'],
            ['code' => 'misdemeanor', 'name' => 'Misdemeanor'],
            ['code' => 'dui_owi', 'name' => 'DUI / OWI'],
            ['code' => 'traffic', 'name' => 'Traffic'],
            ['code' => 'expungement', 'name' => 'Expungement'],
            ['code' => 'probation_parole', 'name' => 'Probation / Parole'],
            ['code' => 'other_criminal_matter', 'name' => 'Other Criminal Matter'],
        ],
        'personal_injury' => [
            ['code' => 'auto_accident', 'name' => 'Auto Accident'],
            ['code' => 'premises_liability', 'name' => 'Premises Liability'],
            ['code' => 'medical_malpractice', 'name' => 'Medical Malpractice'],
            ['code' => 'product_liability', 'name' => 'Product Liability'],
            ['code' => 'wrongful_death', 'name' => 'Wrongful Death'],
            ['code' => 'other_personal_injury', 'name' => 'Other Personal Injury'],
        ],
        'business_corporate_law' => [
            ['code' => 'business_formation', 'name' => 'Business Formation'],
            ['code' => 'contract_matter', 'name' => 'Contract Matter'],
            ['code' => 'corporate_governance', 'name' => 'Corporate Governance'],
            ['code' => 'business_transaction', 'name' => 'Business Transaction'],
            ['code' => 'business_dispute', 'name' => 'Business Dispute'],
            ['code' => 'merger_acquisition', 'name' => 'Merger / Acquisition'],
            ['code' => 'other_business_matter', 'name' => 'Other Business Matter'],
        ],
        'estate_planning' => [
            ['code' => 'will', 'name' => 'Will'],
            ['code' => 'trust', 'name' => 'Trust'],
            ['code' => 'power_of_attorney', 'name' => 'Power of Attorney'],
            ['code' => 'estate_plan', 'name' => 'Estate Plan'],
            ['code' => 'other_estate_planning', 'name' => 'Other Estate Planning'],
        ],
        'probate_estate_administration' => [
            ['code' => 'probate_administration', 'name' => 'Probate Administration'],
            ['code' => 'estate_administration', 'name' => 'Estate Administration'],
            ['code' => 'guardianship_conservatorship', 'name' => 'Guardianship / Conservatorship'],
            ['code' => 'probate_litigation', 'name' => 'Probate Litigation'],
            ['code' => 'other_probate_matter', 'name' => 'Other Probate Matter'],
        ],
        'real_estate_law' => [
            ['code' => 'residential_transaction', 'name' => 'Residential Transaction'],
            ['code' => 'commercial_transaction', 'name' => 'Commercial Transaction'],
            ['code' => 'landlord_tenant', 'name' => 'Landlord / Tenant'],
            ['code' => 'property_dispute', 'name' => 'Property Dispute'],
            ['code' => 'zoning_land_use', 'name' => 'Zoning / Land Use'],
            ['code' => 'other_real_estate_matter', 'name' => 'Other Real Estate Matter'],
        ],
        'employment_labor_law' => [
            ['code' => 'wrongful_termination', 'name' => 'Wrongful Termination'],
            ['code' => 'discrimination', 'name' => 'Discrimination'],
            ['code' => 'harassment', 'name' => 'Harassment'],
            ['code' => 'wage_hour', 'name' => 'Wage / Hour'],
            ['code' => 'employment_agreement', 'name' => 'Employment Agreement'],
            ['code' => 'other_employment_matter', 'name' => 'Other Employment Matter'],
        ],
        'bankruptcy' => [
            ['code' => 'chapter_7', 'name' => 'Chapter 7'],
            ['code' => 'chapter_11', 'name' => 'Chapter 11'],
            ['code' => 'chapter_13', 'name' => 'Chapter 13'],
            ['code' => 'creditor_representation', 'name' => 'Creditor Representation'],
            ['code' => 'other_bankruptcy_matter', 'name' => 'Other Bankruptcy Matter'],
        ],
        'civil_litigation' => [
            ['code' => 'contract_dispute', 'name' => 'Contract Dispute'],
            ['code' => 'tort_claim', 'name' => 'Tort Claim'],
            ['code' => 'commercial_litigation', 'name' => 'Commercial Litigation'],
            ['code' => 'injunction_equitable_relief', 'name' => 'Injunction / Equitable Relief'],
            ['code' => 'other_civil_litigation', 'name' => 'Other Civil Litigation'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        $practiceAreaIdsByCode = DB::table('practice_areas')->pluck('id', 'code');

        $rows = [];

        foreach ($practiceAreaIdsByCode as $practiceAreaCode => $practiceAreaId) {
            $types = $this->matterTypesByPracticeArea[$practiceAreaCode]
                ?? $this->genericFallback($practiceAreaCode, $practiceAreaIdsByCode);

            foreach ($types as $type) {
                $rows[] = array_merge($type, [
                    'practice_area_id' => $practiceAreaId,
                    'description' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('matter_types')->upsert(
            $rows,
            ['practice_area_id', 'code'],
            ['name', 'is_active', 'updated_at']
        );
    }

    public function down(): void
    {
        $practiceAreaIdsByCode = DB::table('practice_areas')->pluck('id', 'code');

        $allCodes = [];

        foreach ($practiceAreaIdsByCode as $practiceAreaCode => $practiceAreaId) {
            $types = $this->matterTypesByPracticeArea[$practiceAreaCode]
                ?? $this->genericFallback($practiceAreaCode, $practiceAreaIdsByCode);

            foreach ($types as $type) {
                $allCodes[] = ['practice_area_id' => $practiceAreaId, 'code' => $type['code']];
            }
        }

        foreach ($allCodes as $pair) {
            DB::table('matter_types')
                ->where('practice_area_id', $pair['practice_area_id'])
                ->where('code', $pair['code'])
                ->delete();
        }
    }

    /**
     * @param  Collection<string, int>  $practiceAreaIdsByCode
     * @return array<int, array{code: string, name: string}>
     */
    private function genericFallback(string $practiceAreaCode, $practiceAreaIdsByCode): array
    {
        if ($practiceAreaCode === 'other') {
            return [
                ['code' => 'general_matter', 'name' => 'General Matter'],
                ['code' => 'other_matter', 'name' => 'Other Matter'],
            ];
        }

        $practiceAreaName = DB::table('practice_areas')->where('code', $practiceAreaCode)->value('name');

        return [
            ['code' => 'general_matter', 'name' => 'General Matter'],
            ['code' => 'other_'.$practiceAreaCode.'_matter', 'name' => 'Other '.$practiceAreaName.' Matter'],
        ];
    }
};
