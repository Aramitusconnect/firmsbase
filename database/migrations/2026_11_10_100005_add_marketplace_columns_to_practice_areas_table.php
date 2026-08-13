<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 2 (MyAttorney Marketplace Core), section 12: reuses the
 * existing global `practice_areas` catalog as the marketplace's own
 * controlled taxonomy (repository audit confirmed it is already
 * exactly the "no per-firm free text" shape section 12 requires —
 * `App\Models\PracticeArea`, no `BelongsToTenant`, no `firm_id`)
 * rather than duplicating a second taxonomy, which would let a firm's
 * internal specialization list drift from what is actually
 * searchable in the marketplace.
 *
 * Purely additive — no existing column touched, no existing row's
 * data changed by this migration itself (the catalog seed is a
 * separate migration).
 *
 * Staging-safety review (pre-deploy, migration never yet applied
 * anywhere real): `slug`'s unique index is built CONCURRENTLY,
 * separately from the column add, so it never takes a write-blocking
 * lock on practice_areas — a small pre-existing catalog table — for
 * the build's duration. See the chart_of_accounts purpose-index
 * migration for the identical pattern/rationale, including
 * $withinTransaction = false (CREATE INDEX CONCURRENTLY cannot run
 * inside a transaction) and the read-only duplicate preflight, which
 * is expected to always pass today since `slug` is a brand-new
 * column with no backfill (every row is NULL immediately after the
 * column is added, and Postgres never treats two NULLs as
 * duplicates).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('practice_areas', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('code');
            $table->boolean('is_marketplace_visible')->default(false)->after('is_active');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_marketplace_visible');
            $table->json('synonyms')->nullable()->after('sort_order');
        });

        $duplicates = DB::table('practice_areas')
            ->select('slug', DB::raw('COUNT(*) as count'))
            ->whereNotNull('slug')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'Refusing to create practice_areas_slug_unique: '.
                $duplicates->count().' slug value(s) already appear more than once. '.
                'Resolve the duplicates manually — this migration will not delete or '.
                'merge data automatically. First conflicting values: '.
                $duplicates->take(5)->toJson()
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX CONCURRENTLY practice_areas_slug_unique ON practice_areas (slug)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS practice_areas_slug_unique');

        Schema::table('practice_areas', function (Blueprint $table) {
            $table->dropColumn(['slug', 'is_marketplace_visible', 'sort_order', 'synonyms']);
        });
    }
};
