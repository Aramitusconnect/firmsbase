<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * key_destruction_requests — request to crypto-shred a firm's envelope
 * encryption key(s) (tenant_encryption_key_id null means "all active
 * keys for the firm"). Gated by completed offboarding export, retention
 * clearance, and legal-hold clearance BEFORE two-person approval can
 * even begin (KeyDestructionRequestService), and irreversibly executed
 * only after approval (KeyDestructionExecutionService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('key_destruction_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('offboarding_request_id')->nullable()->constrained('offboarding_requests')->nullOnDelete();
            $table->foreignId('tenant_encryption_key_id')->nullable()->constrained('tenant_encryption_keys')->nullOnDelete();

            $table->string('status')->default('requested');
            $table->text('reason');

            $table->foreignId('requested_by_platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancelled_reason')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('key_destruction_requests');
    }
};
