<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 14 — the
 * "abandoned-intake retention sweep" the create-table migration's own
 * docblock already reserved this checkpoint for. Marks the moment
 * MarketplaceIntakeService::purgeExpiredPii() scrubbed a terminal,
 * never-converted intake's identity fields — a sweep-idempotency
 * marker, not a soft-delete (the row and its own
 * marketplace_intake_events audit trail are deliberately preserved).
 * Nullable/additive on the same existing marketplace_intakes table —
 * no new RLS design needed, this table is already FORCE RLS with a
 * non-nullable firm_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_intakes', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable()->after('portal_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_intakes', function (Blueprint $table) {
            $table->dropColumn('purged_at');
        });
    }
};
