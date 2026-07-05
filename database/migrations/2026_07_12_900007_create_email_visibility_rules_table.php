<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_visibility_rules — governs who besides the connecting firm
 * user may see a mailbox's messages. matter_id null = the account-
 * level default rule; matter_id set = a matter-specific override.
 * EmailVisibilityPolicyService resolves: matter-specific rule (if the
 * message is linked to that matter) -> account-level default rule ->
 * hard-default OwnerOnly if no rule row exists at all (fail closed to
 * the most restrictive option, never open). This is a small, fixed-
 * scope policy table, not a generic per-user ACL/grant system (project
 * rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_visibility_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->cascadeOnDelete();

            $table->string('visibility_scope')->default('owner_only');
            $table->foreignId('created_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->unique(['email_account_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_visibility_rules');
    }
};
