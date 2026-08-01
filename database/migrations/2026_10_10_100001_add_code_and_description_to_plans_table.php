<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the two fields an admin-facing Create/Edit Plan form needs that
 * the original plans table never carried: a human-chosen unique
 * `code` (distinct from the random `uuid` — plans were "created
 * out-of-band" before this pass, per PlanResource's own prior
 * docblock, so nothing ever needed a stable external identifier) and
 * an optional `description` for internal/safe catalog notes. No
 * `currency` column is added — MoneyDisplay::fromCents()'s own
 * docblock already documents that every platform-billing amount in
 * this schema is USD-only by approved decision; this migration
 * preserves that precedent rather than introducing a second one.
 *
 * `code` is nullable at the schema level only so this migration can
 * run against a table that already has rows without a backfill step;
 * the application layer (PlanService::create()) always requires and
 * validates it for every new Plan going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('name');
            $table->text('description')->nullable()->after('support_access_level');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['code', 'description']);
        });
    }
};
