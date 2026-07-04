<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Additive ALTER on the baseline `users` table. Adds uuid, profile,
 * and 2FA/invitation fields. Deliberately does NOT add users.firm_id —
 * users are global identities; firm membership lives in firm_users.
 *
 * Backfills uuid for any pre-existing rows before making the column
 * non-nullable — this is the one place a uuid could ever be generated
 * outside of HasPublicUuid's creating() hook (for rows that already
 * existed before this migration ran). Every row created AFTER this
 * migration relies on app/Models/User.php actually using HasPublicUuid
 * — see PATCH-INSTRUCTIONS/User.php.PATCH.md. This migration only
 * makes the column exist and backfills history; it cannot make the
 * model populate it going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable()->unique()->after('id');
            $table->string('phone', 30)->nullable();
            $table->string('title')->nullable();

            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invitation_token')->nullable()->unique();
            $table->timestamp('invitation_accepted_at')->nullable();
            $table->timestamp('invitation_expires_at')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });

        DB::table('users')->whereNull('uuid')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'uuid' => (string) Str::uuid7(),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn([
                'uuid',
                'phone',
                'title',
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'is_active',
                'invitation_token',
                'invitation_accepted_at',
                'invitation_expires_at',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
