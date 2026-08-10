<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 1 — Domain & Security Boundary Architecture, section 17.
 * Dedicated password-reset token tables for the `clients` and
 * `platform_admins` auth providers, mirroring the shape of Laravel's
 * own stock `password_reset_tokens` table (created for the `users`
 * provider in 0001_01_01_000000_create_users_table.php).
 *
 * Deliberately NOT sharing the `users` table's password_reset_tokens
 * table: Laravel's password broker keys a reset token by email only,
 * with no guard/provider discriminator in the row itself — three
 * separate identity tables (User, Client, PlatformAdmin) sharing one
 * token table would let a valid reset token issued for one identity
 * be replayed against a different identity that happens to share the
 * same email address (rare, but not impossible — a client could
 * plausibly share an email with a firm employee or platform-ops
 * staffer). A dedicated table per provider closes that narrow
 * cross-identity token-collision risk structurally, not by convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('platform_admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_password_reset_tokens');
        Schema::dropIfExists('platform_admin_password_reset_tokens');
    }
};
