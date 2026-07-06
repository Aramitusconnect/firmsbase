<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * retention_policies — versioned retention rule, either a platform
 * default (firm_id null) or a firm-specific override (firm_id set).
 * Effective-policy resolution (firm override wins over platform
 * default; no policy means not cleared, never unrestricted) lives in
 * RetentionPolicyService, not here.
 *
 * allows_client_replacement/preserves_audit_history_required (approved
 * decision #4) back the "clients cannot hard-delete submitted documents
 * unless firm policy allows replacement and preserves audit history"
 * rule for record_type=document_category rows — no new settings table
 * was created for this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();
            $table->string('record_type');
            $table->string('document_category')->nullable();
            $table->string('practice_area')->nullable();
            $table->string('jurisdiction')->nullable();

            $table->unsignedInteger('retention_period_days')->nullable();
            $table->boolean('is_permanent')->default(false);
            $table->boolean('allows_client_replacement')->default(false);
            $table->boolean('preserves_audit_history_required')->default(true);

            $table->text('legal_basis')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('supersedes_policy_id')->nullable()->constrained('retention_policies')->nullOnDelete();

            $table->text('reason')->nullable();
            $table->foreignId('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->foreignId('created_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'record_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
