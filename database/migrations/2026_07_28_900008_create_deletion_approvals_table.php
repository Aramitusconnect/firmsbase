<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deletion_approvals — mirrors key_destruction_approvals exactly, but
 * wraps HighRiskChangeType::ProductionDataDeletion (the EXISTING case,
 * reused per approved decision #2 — not the new CryptographicKeyDestruction
 * case, which is reserved for key destruction only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deletion_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('deletion_request_id')->constrained('deletion_requests')->cascadeOnDelete();
            $table->foreignId('high_risk_change_request_id')->nullable()->constrained('high_risk_change_requests')->nullOnDelete();

            $table->string('status')->default('pending');
            $table->foreignId('first_approved_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable();
            $table->foreignId('second_approved_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable();
            $table->foreignId('denied_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('denied_at')->nullable();
            $table->text('denial_reason')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('deletion_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deletion_approvals');
    }
};
