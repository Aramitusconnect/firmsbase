<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firm Feature Manifest §13 (Firm Settings): "no address, phone, logo,
 * or business-hours field... anywhere in the schema today." This
 * migration closes the address/phone half of that gap only — additive
 * ONLY, adding five NULLABLE string columns to the EXISTING firms
 * table (created in Phase 1), matching the exact "Schema::table(...)
 * add nullable columns, dropColumn() on down()" shape used by every
 * other additive column migration on this table's neighbors (e.g.
 * add_seat_class_to_firm_users_table, add_firm_user_2fa_mode_to_firm_
 * settings_table).
 *
 * All five columns are nullable with no default: every existing firm
 * row is left completely unchanged by this migration (no backfill, no
 * data loss) — only newly-edited rows via the firm-panel Firm Settings
 * page (Tier 3-C) ever populate them. No logo/business-hours column is
 * added here — the manifest confirms no real file storage pipeline
 * exists anywhere (a logo upload would be fake/broken), and
 * business-hours was out of this task's explicit scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->string('address_line1')->nullable()->after('data_region');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city')->nullable()->after('address_line2');
            $table->string('postal_code')->nullable()->after('city');
            $table->string('phone_number')->nullable()->after('postal_code');
        });
    }

    public function down(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->dropColumn(['address_line1', 'address_line2', 'city', 'postal_code', 'phone_number']);
        });
    }
};
