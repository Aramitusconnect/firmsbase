<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * key_destruction_approvals — the two-person approval trail for a
 * key_destruction_requests row. Wraps the EXISTING
 * HighRiskPlatformChangePolicyService via high_risk_change_request_id
 * (HighRiskChangeType::CryptographicKeyDestruction) — this table never
 * re-implements approval logic, it only links to and mirrors the real
 * engine's outcome for domain-specific querying. status reuses the
 * EXISTING HighRiskChangeRequestStatus enum (no duplicate enum).
 * Append-only once written (approvals are a historical decision record).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('key_destruction_approvals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('key_destruction_request_id')->constrained('key_destruction_requests')->cascadeOnDelete();
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

            $table->index('key_destruction_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('key_destruction_approvals');
    }
};
