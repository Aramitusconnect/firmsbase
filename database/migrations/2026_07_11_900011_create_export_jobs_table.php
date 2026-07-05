<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * export_jobs — firm_id non-nullable, which is exactly why ExportJob
 * DOES use BelongsToTenant (approved correction #10). The three
 * governance-check booleans record whether
 * ExportGovernancePolicyService's legal-hold/retention/offboarding
 * checks were run and passed at request time — an audit trail of the
 * governance decision, not the decision logic itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('export_type');
            $table->string('status')->default('requested');

            $table->foreignId('requested_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('requested_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->text('reason')->nullable();
            $table->boolean('legal_hold_checked')->default(false);
            $table->boolean('retention_checked')->default(false);
            $table->boolean('offboarding_checked')->default(false);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failed_reason')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('export_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
    }
};
