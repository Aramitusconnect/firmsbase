<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_firms — Mission 2 (MyAttorney Marketplace Core), section 8.
 * The authoritative marketplace directory listing — FirmsBase-owned,
 * NOT dependent on Google/AI/Maps/any third-party directory (section
 * 5). Global platform data, not tenant-owned: no firm_id-scoped RLS
 * (see RowLevelSecurityCoverageMappingService::EXEMPT_TABLES) — a
 * directory listing must exist and be publicly readable independent of
 * any single firm's tenant context, including for firms that have
 * never signed up for FirmsBase at all (section 6).
 *
 * `firm_id` is a nullable, plain bare FK to `firms.id` — modeled on
 * FirmIntegration.connected_by_firm_user_id's own established pattern
 * (nullable, nullOnDelete(), app-level consistency verified separately
 * from DB-level constraints) rather than a composite FK, since `firms`
 * itself carries no tenant scope to be composite against. A directory
 * listing must never disappear merely because its linked tenant Firm
 * is deleted — nullOnDelete(), not cascadeOnDelete().
 *
 * `is_claimed`/`claimed_at` and `is_marketplace_member`/
 * `membership_activated_at` are deliberately separate, independent
 * booleans (section 17/18: claiming does not require a paid
 * subscription; membership is a distinct product/service relationship,
 * never inferred from firm_id being set). DirectoryFirmProfileLevel is
 * always DERIVED from these two columns by the model, never its own
 * stored, independently-driftable column (section 15).
 *
 * The full CLAIM lifecycle (Pending/EvidenceRequired/UnderReview/...)
 * lives in the separate directory_claims table (Mission 2 checkpoint
 * 6) — is_claimed/claimed_at here are a denormalized read-path cache
 * updated exactly once, by the claim-approval action, not a duplicate
 * state machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_firms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();

            $table->string('slug')->unique();
            $table->string('legal_name');
            $table->string('display_name');
            $table->string('name_normalized');

            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('public_email')->nullable();
            $table->unsignedSmallInteger('founding_year')->nullable();
            $table->text('description')->nullable();
            $table->json('consultation_modes')->nullable();
            $table->boolean('accepting_inquiries')->default(false);

            $table->boolean('is_claimed')->default(false);
            $table->timestamp('claimed_at')->nullable();
            $table->boolean('is_marketplace_member')->default(false);
            $table->timestamp('membership_activated_at')->nullable();

            $table->string('publication_state')->default('draft');

            $table->string('source_type');
            $table->string('source_reference')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_confirmed_by_firm_at')->nullable();
            $table->unsignedTinyInteger('completeness_score')->default(0);

            $table->timestamps();

            $table->index('name_normalized');
            $table->index('publication_state');
            $table->index(['is_claimed', 'is_marketplace_member']);
            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_firms');
    }
};
