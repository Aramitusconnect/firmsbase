<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_verifications — Mission 2 (MyAttorney Marketplace Core),
 * section 24. Multi-dimensional verification: one row per
 * (verifiable, dimension) pair, each carrying its own state/verified-
 * at/verified-by/source/expiration/revocation — never a single
 * Boolean on directory_firms/directory_attorneys.
 *
 * `verifiable_type`/`verifiable_id` is a lightweight polymorphic
 * reference (same pattern as `timeline_events.subject_type`/
 * `subject_id`) — a dimension's subject is whichever marketplace
 * entity it actually verifies (DirectoryFirm for FirmAuthority/
 * DomainEmail/Membership, DirectoryAttorney for AttorneyIdentity/
 * AttorneyLicense, FirmOffice for OfficeAddress; Phone may attach to
 * either a DirectoryFirm or a FirmOffice). No `firm_id` column at
 * all — same Global/no-tenant-RLS reasoning as every other Mission 2
 * marketplace table (ownership flows through the polymorphic subject,
 * itself already global).
 *
 * A row is mutated in place by MarketplaceVerificationService as its
 * state transitions (verify/revoke/expire) — real history is preserved
 * via the existing security_events audit trail (section 58), not by
 * inserting a new row per transition. This is the same "one row is
 * the state machine, audit events are the history" shape checkpoint
 * 6's directory_claims already established, deliberately NOT full
 * event-sourcing (section 25 explicitly does not require it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_verifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('verifiable_type');
            $table->unsignedBigInteger('verifiable_id');

            $table->string('dimension');
            $table->string('state')->default('pending');
            $table->string('source')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['verifiable_type', 'verifiable_id', 'dimension'], 'directory_verifications_subject_dimension_unique');
            $table->index(['verifiable_type', 'verifiable_id']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_verifications');
    }
};
