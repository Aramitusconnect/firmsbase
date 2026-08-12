<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * marketplace_intakes — Mission 3 (MyAttorney Conversion + AI Intake),
 * checkpoint 1 ("prospect/intake domain model"). Represents a single
 * visitor's Firm-scoped secure intake session, from the moment they
 * select a specific claimed Firm through submission, Firm review, and
 * (optionally) conversion. The pre-Firm discovery stage (MyAttorney
 * search/browse, Mission 2) never creates a row here — a row only
 * exists once a visitor has committed to a specific Firm, which is
 * why firm_id is NOT NULL from creation, unlike directory_firms'
 * nullable firm_id.
 *
 * This is a genuinely new, DirectTenant table — not a reuse of
 * firm_leads. firm_leads (see its own migration) is the Firm-side
 * canonical lead concept, created/managed by an authenticated
 * FirmUser and consumed directly by LeadConversionService. A
 * marketplace intake is created by an ANONYMOUS public visitor with
 * no FirmUser session at all, needs its own privacy/resumability/
 * abandonment lifecycle (sections 9-11, 60-64 of the Mission 3 spec),
 * and must never be mistaken for an authoritative Firm-created lead.
 * The bridge between the two is deliberate and explicit:
 * ConvertMarketplaceProspectService (checkpoint 11) creates or
 * resolves a firm_leads row (converted_firm_lead_id below) as part of
 * conversion, then defers to the EXISTING LeadConversionService for
 * the actual Client creation — this table never creates a Client
 * directly, matching the Client model's own "single legitimate path"
 * rule.
 *
 * uuid (HasPublicUuid, UUIDv7) is the ONLY identifier ever placed in
 * the public resumable intake URL, mirroring payment_requests exactly
 * — see that table's own migration for the full opaque-identifier
 * rationale. A companion self-lookup RLS policy
 * (2026_11_12_100005_add_self_lookup_clause_to_marketplace_intakes_rls_policy.php)
 * lets an anonymous visitor holding only this uuid resume their own
 * intake — see TenantContextService::withMarketplaceIntakeSelfLookupContext().
 *
 * directory_firm_id is nullable + nullOnDelete(), mirroring
 * directory_firms.firm_id's own established "a public record must
 * never disappear because a linked row was deleted" convention — but
 * is expected to always be populated at creation by the intake-start
 * flow (checkpoint 2), since a visitor always arrives via a specific
 * marketplace profile.
 *
 * practice_area_id records the pre-Firm AI-assisted issue-category
 * classification (Mission 3 section: "AI may only classify pre-Firm
 * issue category into a practice area") — never an AI-assigned
 * conflict/eligibility/legal conclusion.
 *
 * structured_data (jsonb) holds ONLY validated, schema-conformant
 * intake answers (Mission 3's "structured data vs raw transcript"
 * separation) — free-text narrative/conversational transcript, when
 * the conversational AI intake mode is used (checkpoint 6), is
 * intentionally NOT stored on this row; it lives in a separate,
 * more tightly access-controlled store introduced by that checkpoint,
 * so that the common path of reading a lead's structured answers
 * never has to filter out raw prospect narrative.
 *
 * converted_firm_lead_id / converted_client_id / converted_at are set
 * ONLY by ConvertMarketplaceProspectService, mirroring firm_leads'
 * own "converted_client_id set only by LeadConversionService" project
 * rule verbatim — a marketplace intake must never silently become a
 * lead or a client any other way.
 *
 * expires_at / last_resumed_at support the resumable-link lifecycle
 * (checkpoint 2) and the abandoned-intake retention sweep (checkpoint
 * 14) — both deliberately deferred to their own checkpoints; this
 * migration only reserves the columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_intakes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('directory_firm_id')->nullable()->constrained('directory_firms')->nullOnDelete();
            $table->foreignId('practice_area_id')->nullable()->constrained('practice_areas')->nullOnDelete();

            $table->string('status')->default('started');

            $table->string('prospect_name')->nullable();
            $table->string('prospect_email')->nullable();
            $table->string('prospect_phone')->nullable();

            $table->jsonb('structured_data')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('under_review_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();

            $table->foreignId('converted_firm_lead_id')->nullable()->constrained('firm_leads')->nullOnDelete();
            $table->foreignId('converted_client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_resumed_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index('directory_firm_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_intakes');
    }
};
