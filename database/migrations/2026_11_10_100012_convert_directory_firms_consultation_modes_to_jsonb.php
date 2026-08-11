<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 2 (MyAttorney Marketplace Core), checkpoint 5 fix. Postgres's
 * `json` type has no equality operator, so `SELECT DISTINCT *` against
 * `directory_firms` (required by MarketplaceSearchService::candidates()
 * to prevent a firm matching via multiple relations from being
 * returned twice — section 85 AH) fails with "could not identify an
 * equality operator for type json" whenever a `json` column is in the
 * selected row. `jsonb` has one, and is Postgres's own recommended
 * type for columns that are ever compared or indexed. No application
 * code change needed — `whereJsonContains()`/the `array` Eloquent cast
 * already round-trip through jsonb-compatible SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE directory_firms ALTER COLUMN consultation_modes TYPE jsonb USING consultation_modes::jsonb');
    }

    public function down(): void
    {
        if (Schema::hasColumn('directory_firms', 'consultation_modes')) {
            DB::statement('ALTER TABLE directory_firms ALTER COLUMN consultation_modes TYPE json USING consultation_modes::json');
        }
    }
};
