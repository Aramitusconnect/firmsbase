<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 1 — Domain & Security Boundary Architecture. Adds the
 * columns needed for `Client` to become a real Laravel-authenticatable
 * identity for client.firmsvault.com's own guard/session — the
 * previously-existing portal_status/portal_invitation_* columns only
 * ever prepared *invitation* lifecycle state (see
 * 2026_07_05_600010_create_clients_table.php's own docblock); nothing
 * before this migration let a Client actually log in anywhere.
 * Nullable throughout: a Client only gains a usable password once
 * their portal invitation is accepted (a not-yet-invited/not-yet-
 * accepted Client remains correctly unable to authenticate at all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->rememberToken()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['password', 'remember_token']);
        });
    }
};
