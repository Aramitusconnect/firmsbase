<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 39B: adds firm_user_2fa_mode to the EXISTING firm_settings
 * table (Phase 1), mirroring client_2fa_mode's exact shape (string
 * column, TwoFactorMode enum cast, defaults to 'optional'). Reuses the
 * existing TwoFactorMode enum — no new enum was needed. Defaults to
 * 'optional' so existing dev/test firm users are never locked out by
 * this column's mere presence; only a firm that is explicitly switched
 * to 'required' is subject to FirmUser2faPolicyService's compliance
 * check. No new table, no User/FirmUser schema change — User already
 * owns two_factor_secret/two_factor_recovery_codes/two_factor_confirmed_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_settings', function (Blueprint $table) {
            $table->string('firm_user_2fa_mode')->default('optional')->after('client_2fa_mode');
        });
    }

    public function down(): void
    {
        Schema::table('firm_settings', function (Blueprint $table) {
            $table->dropColumn('firm_user_2fa_mode');
        });
    }
};
