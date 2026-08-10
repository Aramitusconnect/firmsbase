<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mission 1 (canonical reconstruction) — Domain & Security Boundary
 * Architecture. The `platform_admins` guard's own dedicated
 * password-reset token table, mirroring the shape of the existing
 * `password_reset_tokens` (users) and `client_portal_password_reset_tokens`
 * (client_portal_users) tables. No `platform_admins` password-reset
 * broker existed before this mission — the admin panel had no
 * self-service password-reset flow at all.
 *
 * Deliberately its OWN table, not shared with either of the other two
 * — Laravel's password broker keys a reset token by email only, with
 * no guard/provider discriminator in the row itself, so sharing a
 * table across identity types would let a valid reset token issued for
 * one identity be replayed against a different identity that happens
 * to share the same email address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_password_reset_tokens');
    }
};
