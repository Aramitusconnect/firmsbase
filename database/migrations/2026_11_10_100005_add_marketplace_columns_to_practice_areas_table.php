<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('practice_areas', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('code');
            $table->boolean('is_marketplace_visible')->default(false)->after('is_active');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_marketplace_visible');
            $table->json('synonyms')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('practice_areas', function (Blueprint $table) {
            $table->dropColumn(['slug', 'is_marketplace_visible', 'sort_order', 'synonyms']);
        });
    }
};
