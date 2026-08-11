<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mission 2 (MyAttorney Marketplace Core), section 12: seeds the
 * canonical marketplace practice-area catalog through maintainable
 * data, not a hardcoded list inside business logic (the illustrative
 * category list from the mission brief lives only here, in a data
 * migration — MarketplaceSearchService/MarketplaceRankingService and
 * every other service query `practice_areas` at runtime, never a
 * hardcoded array of these names). Follows this repository's own
 * established "one small seed-only migration per catalog addition"
 * convention (see e.g.
 * 2026_09_21_150002_seed_microsoft365_integration_provider_catalog_entry.php).
 *
 * Uses updateOrInsert() keyed on `code` — idempotent against any
 * pre-existing practice_areas row sharing a code (this catalog is
 * shared with the pre-existing matter-type/template-pack usage of
 * this table), and safe to re-run.
 */
return new class extends Migration
{
    private const CATEGORIES = [
        ['code' => 'personal-injury', 'name' => 'Personal Injury'],
        ['code' => 'criminal-defense', 'name' => 'Criminal Defense'],
        ['code' => 'family-law', 'name' => 'Family Law'],
        ['code' => 'immigration', 'name' => 'Immigration'],
        ['code' => 'estate-planning', 'name' => 'Estate Planning'],
        ['code' => 'probate', 'name' => 'Probate'],
        ['code' => 'business-law', 'name' => 'Business Law'],
        ['code' => 'employment-law', 'name' => 'Employment Law'],
        ['code' => 'real-estate', 'name' => 'Real Estate'],
        ['code' => 'bankruptcy', 'name' => 'Bankruptcy'],
        ['code' => 'civil-litigation', 'name' => 'Civil Litigation'],
        ['code' => 'workers-compensation', 'name' => "Workers' Compensation"],
        ['code' => 'social-security-disability', 'name' => 'Social Security Disability'],
        ['code' => 'tax', 'name' => 'Tax'],
        ['code' => 'intellectual-property', 'name' => 'Intellectual Property'],
        ['code' => 'consumer-law', 'name' => 'Consumer Law'],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::CATEGORIES as $index => $category) {
            DB::table('practice_areas')->updateOrInsert(
                ['code' => $category['code']],
                [
                    'name' => $category['name'],
                    'slug' => $category['code'],
                    'is_active' => true,
                    'is_marketplace_visible' => true,
                    'sort_order' => ($index + 1) * 10,
                    'synonyms' => json_encode([]),
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('practice_areas')
            ->whereIn('code', array_column(self::CATEGORIES, 'code'))
            ->update(['is_marketplace_visible' => false]);
    }
};
