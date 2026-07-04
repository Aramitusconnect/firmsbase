<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * support_access_sessions — time-limited (expires_at is NOT nullable),
 * actor-bound (platform_admin_id), firm-scoped (firm_id, denormalized
 * from the parent request for fast scoping queries). Expired sessions
 * must not authorize access: SupportAccessSessionService/
 * SupportAccessPolicyService check expires_at/status on every access
 * check, never trusting a cached "active" flag alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_access_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('support_access_request_id')->constrained('support_access_requests')->cascadeOnDelete();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();

            $table->string('status')->default('active');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();

            $table->foreignId('revoked_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('platform_admin_id');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_sessions');
    }
};
