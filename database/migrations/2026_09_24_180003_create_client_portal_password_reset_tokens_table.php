<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * client_portal_password_reset_tokens — Checkpoint 4 ("Plaid financial
 * evidence add-on"), Client Portal authentication foundation
 * (checkpoint4-design-matter-and-client-portal.md §2.6.2). Byte-for-byte
 * the same shape as Laravel's own stock `password_reset_tokens` table
 * (0001_01_01_000000_create_users_table.php) — email primary key,
 * token, created_at only.
 *
 * No firm_id, no RLS: this table is looked up by email BEFORE any
 * authentication (and therefore before any tenant context) can exist —
 * the exact same reason the stock `password_reset_tokens` table itself
 * has no RLS, and the same reasoning `integration_oauth_states`/
 * `integration_webhook_receipts` already carved out deliberately for
 * genuinely pre-tenant-context tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_password_reset_tokens');
    }
};
