<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * high_risk_change_requests — reason-required, two-person-approval-ready
 * workflow FOUNDATION only, for: trust_mode_activation,
 * production_data_deletion, payment_trust_setting_change,
 * emergency_support_access. This table stores approval STATE only.
 * There is no "executed" status and no execution logic anywhere in
 * Phase 7 — approving a request here does not perform trust mode
 * activation, does not delete production data, and does not move
 * trust/IOLTA money. Exact column set per the approved manifest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('high_risk_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('change_type');
            $table->string('status')->default('pending');
            $table->text('reason');

            $table->foreignId('requested_by')->constrained('platform_admins')->cascadeOnDelete();

            $table->foreignId('first_approved_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable();

            $table->foreignId('second_approved_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable();

            $table->foreignId('denied_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('denied_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('change_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('high_risk_change_requests');
    }
};
