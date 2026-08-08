<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: adds `purchased_seats` (nullable integer) to the
 * EXISTING firm_licenses table. Does not recreate firm_licenses, does
 * not touch any other column.
 *
 * BUSINESS MODEL (Firm Feature Manifest §12 — flat per-firm seat
 * licensing, replacing the previously-dead-end per-`SeatClass`
 * `plan_limits`/`SeatAllocation` model as the commercial seat source):
 * each firm purchases a single FLAT number of seats — every FirmUser
 * row (any of the 6 `FirmUserRole` values, including FirmOwner)
 * consumes exactly one seat, regardless of role. `purchased_seats` is
 * intentionally a plain mutable nullable integer, not a separate
 * ledger/history table — a later increase/decrease is just an UPDATE,
 * no new migration required for that. NULL means "no purchased-seat
 * quantity has been configured for this license" (either a legacy
 * commercial firm predating this column, or genuinely no seats
 * assigned yet) — `FirmSeatCapacityService` treats NULL as "seats not
 * configured," never as zero or unlimited.
 *
 * Deliberately NOT backfilled by this migration — see
 * `firms:report-missing-purchased-seats` (dry-run/report) and its
 * `--apply` mode: no production code may invent a seat quantity for an
 * existing commercial firm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_licenses', function (Blueprint $table) {
            $table->integer('purchased_seats')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('firm_licenses', function (Blueprint $table) {
            $table->dropColumn('purchased_seats');
        });
    }
};
