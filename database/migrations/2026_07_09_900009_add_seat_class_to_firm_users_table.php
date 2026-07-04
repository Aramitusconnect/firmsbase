<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: adds a NULLABLE seat_class column to the EXISTING
 * firm_users table (created in Phase 1). Does not recreate firm_users.
 * Does NOT touch FirmUserRole (approved decision — that enum stays
 * exactly as-is, no read_only role is added to it).
 *
 * No backfill of existing rows: leaving seat_class null is the correct
 * state for every row that predates Phase 6. The default seat class is
 * derived at READ TIME by FirmUser::effectiveSeatClass() from the
 * existing role column (FirmOwner/Attorney -> attorney; Paralegal/
 * LegalAssistant/Receptionist/BillingStaff -> staff) — read_only can
 * ONLY be reached by an explicit seat_class value, never a default,
 * since no role implies it (approved decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_users', function (Blueprint $table) {
            $table->string('seat_class')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('firm_users', function (Blueprint $table) {
            $table->dropColumn('seat_class');
        });
    }
};
