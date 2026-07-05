<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * api_keys — a single table covering BOTH the platform-internal API
 * foundation and the firm/customer API foundation, distinguished by
 * key_type (platform|firm), per approved correction #2 (no separate
 * platform_api_keys/firm_api_keys tables). firm_id is nullable —
 * platform-type keys carry no firm_id at all, which is exactly why
 * BelongsToTenant is NOT applied to this model (approved correction
 * #10).
 *
 * Only a hashed_secret is ever stored (project rule: "store only
 * hashed API key secrets"). last_four is the last 4 characters of the
 * raw secret, safe to display for key identification without ever
 * reconstructing the secret. The raw secret itself is returned once by
 * ApiKeyService::create()/rotate() and never persisted anywhere.
 *
 * created_by is split per approved correction #1 — never a single
 * generic `users` FK, since a key may be created either by a firm's
 * own staff member (firm_users) or by platform operations staff
 * (platform_admins, Phase 7's distinct identity table). Exactly one of
 * the two columns is expected to be set; enforcing "exactly one" is
 * ApiKeyService's job, not a DB constraint (some databases don't
 * support conditional check constraints portably).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();
            $table->string('key_type');

            $table->string('name');
            $table->string('hashed_secret');
            $table->string('last_four', 4);

            $table->string('status')->default('active');
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();

            $table->foreignId('rotated_from_id')->nullable()->constrained('api_keys')->nullOnDelete();

            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason')->nullable();

            $table->foreignId('created_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('key_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
