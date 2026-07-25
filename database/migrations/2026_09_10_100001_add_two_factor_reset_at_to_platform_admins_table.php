<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FirmsVault Admin Control Center — MFA design proposal §2/§8. Adds a
 * nullable `two_factor_reset_at` timestamp to `platform_admins`, stamped
 * by PlatformAdminMfaResetService::reset() (and the emergency Artisan
 * command, which goes through the same service) whenever an authorized
 * SuperAdmin clears another admin's MFA state. Compared against the
 * session's own authentication timestamp by
 * EnsurePlatformAdminMfaIsEnrolledAndVerified so a reset takes effect
 * immediately — forcing the target's current session to log out on its
 * very next request, not merely on their next natural logout/re-login.
 * Never written to on ordinary enroll/disable-by-self flows — only a
 * reset performed BY ANOTHER acting SuperAdmin (or the emergency
 * command) bumps it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->timestamp('two_factor_reset_at')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropColumn('two_factor_reset_at');
        });
    }
};
