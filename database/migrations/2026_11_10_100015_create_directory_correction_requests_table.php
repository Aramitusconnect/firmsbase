<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_correction_requests — Mission 2 (MyAttorney Marketplace
 * Core), section 51. The correction/removal workflow's real state
 * machine — canonical states (Pending/UnderReview/Approved/Rejected/
 * Resolved) with a full audit trail, matching directory_claims'
 * established "one row is the state machine, security_events is the
 * history" shape.
 *
 * Deliberately submittable by an unauthenticated public visitor (not
 * just a claimed Firm's own owner) — anyone noticing an incorrect
 * address, a duplicate listing, or a closed firm should be able to
 * flag it, matching how comparable public directories work.
 * `reporter_firm_user_id` is an optional attribution pointer when the
 * reporter happens to be authenticated (never a scoping boundary,
 * same shape as directory_firms.firm_id) — `reporter_name`/
 * `reporter_email` are plain nullable strings for the common
 * unauthenticated case.
 *
 * Global platform data, no real firm_id column at all — same RLS-
 * exemption reasoning as every other Mission 2 marketplace table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_correction_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('directory_firm_id')->constrained('directory_firms')->cascadeOnDelete();

            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('correction_type');
            $table->string('state')->default('pending');
            $table->text('description');

            $table->string('reporter_name')->nullable();
            $table->string('reporter_email')->nullable();
            $table->foreignId('reporter_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->text('reviewer_notes')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->timestamps();

            $table->index(['directory_firm_id', 'state']);
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_correction_requests');
    }
};
