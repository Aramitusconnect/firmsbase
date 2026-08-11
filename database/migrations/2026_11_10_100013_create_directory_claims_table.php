<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_claims — Mission 2 (MyAttorney Marketplace Core), sections
 * 20-23. The real claim lifecycle promised by directory_firms' own
 * docblock. `is_claimed`/`claimed_at` on directory_firms stay a
 * denormalized read-path cache, updated exactly once by
 * MarketplaceClaimService::approve() — this table is the actual,
 * auditable state machine.
 *
 * `firm_id` here is NOT the same shape as directory_firms.firm_id: it
 * is the claimant's real, non-nullable tenant Firm (claiming happens
 * from an authenticated session on app.firmsvault.com — section 60 —
 * so a claim can never exist without one). Still classified Global/
 * RLS-exempt rather than tenant-owned, though, for the same reason
 * directory_firms itself is: duplicate/conflicting-claim detection and
 * platform-admin review both require reading across every claimant
 * firm's claims for one global directory_firm_id, which a firm-scoped
 * RLS policy would make impossible to express as a plain query.
 * Authorization for a Firm user's own access is instead enforced
 * explicitly in application code (MarketplaceClaimAccessPolicyService),
 * comparing the claim's real firm_id against the acting FirmUser's own
 * tenant context — never inferred from a client-submitted value
 * (section 59).
 *
 * `claimant_firm_user_id` uses nullOnDelete() (preserve claim history
 * even if the submitting user is later deactivated/deleted) while
 * `directory_firm_id`/`firm_id` use cascadeOnDelete() — a claim without
 * its target directory listing or its claimant tenant is meaningless.
 *
 * No file-upload evidence column exists here by design — checkpoint 6
 * deliberately ships only text-based claim_basis/reviewer_notes
 * (non-file verification paths), per the Mission 2 design doc's own
 * "claim evidence file upload deferred" decision. If a future
 * checkpoint adds file evidence, it must route through the existing
 * private quarantine -> real scanner -> safe release pipeline
 * (section 79/section 21), never around it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_claims', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('directory_firm_id')->constrained('directory_firms')->cascadeOnDelete();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('claimant_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->string('state')->default('pending');

            $table->text('claim_basis')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('revocation_reason')->nullable();

            $table->foreignId('conflicts_with_claim_id')->nullable()->constrained('directory_claims')->nullOnDelete();

            $table->timestamp('submitted_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['directory_firm_id', 'state']);
            $table->index(['firm_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_claims');
    }
};
