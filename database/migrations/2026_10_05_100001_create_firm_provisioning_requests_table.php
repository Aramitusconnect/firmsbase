<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firm_provisioning_requests — the idempotency ledger for
 * FirmProvisioningService::provision(). Platform-level (cross-firm), not
 * tenant-owned — mirrors `trial_requests`' own Global shape, not
 * FORCE-RLS-protected and not FK-free (unlike
 * `provider_operation_attempts`): the durability hazard that FK-free
 * table exists for is a cross-session write racing an ambient
 * transaction's row lock across an outbound HTTP call. Nothing here
 * ever holds a lock across network I/O — the entire provisioning
 * transaction is local database work, and invitation delivery happens
 * only after that transaction has already committed — so an ordinary
 * FK-bearing table on the default connection is correct and safe.
 *
 * `idempotency_key` is unique: the single row this INSERT either wins or
 * loses IS the compare-and-set gate for "two concurrent submissions
 * create one Firm." `request_payload_hash` lets a resumed/retried
 * request be distinguished from a genuinely different request that
 * accidentally reused an old key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('idempotency_key')->unique();
            $table->string('request_payload_hash');

            $table->foreignId('requested_by_platform_admin_id')->constrained('platform_admins')->restrictOnDelete();
            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('pending');
            $table->string('failure_category')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_provisioning_requests');
    }
};
