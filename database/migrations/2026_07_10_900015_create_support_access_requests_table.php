<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * support_access_requests — request-based, firm-approved (unless
 * emergency), reason-required, time-limited (requested_duration_minutes),
 * actor-bound (requested_by), firm-scoped (firm_id). approved_by/
 * denied_by reference firm_users (the FIRM'S OWN approver), not
 * platform_admins — support access must be approved BY THE FIRM, not by
 * another platform staff member. emergency_justification is the
 * stronger audit field required specifically when access_type =
 * emergency, in addition to the always-required reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('platform_admins')->cascadeOnDelete();

            $table->string('access_type')->default('standard');
            $table->text('reason');
            $table->string('status')->default('requested');

            $table->foreignId('approved_by')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('denied_by')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('denied_at')->nullable();

            $table->unsignedInteger('requested_duration_minutes');
            $table->text('emergency_justification')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('status');
            $table->index('requested_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_requests');
    }
};
