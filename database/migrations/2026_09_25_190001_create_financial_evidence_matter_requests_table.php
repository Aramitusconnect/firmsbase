<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_matter_requests — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.1;
 * checkpoint4-combined-design.md §1.4/§9.4). The firm's OUTBOUND ASK:
 * "please connect a financial account for this matter" — created
 * before any client action, pre-consent. The Client Portal's
 * `PlaidRequestReviewPage` (checkpoint4-combined-design.md §9.4) reads
 * the requesting matter/firm/purpose from this row; the firm-side
 * `PlaidMatterRequestsPage` creates it.
 *
 * `status` tracks the request's own lifecycle
 * (pending -> reviewed -> consented/declined) — separate from, and
 * upstream of, the actual consent DECISION recorded on
 * `financial_evidence_client_consents` (request -> consent ->
 * authorization, per the combined design's own three-table naming
 * lane).
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape (blanket
 * rule, checkpoint4-design-workspace-and-admin-ui.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_matter_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('requested_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->text('purpose');
            $table->json('requested_products_json'); // e.g. ['bank_account', 'transaction', 'income']
            $table->string('status')->default('pending'); // pending|reviewed|consented|declined|cancelled

            $table->timestamp('requested_at');
            $table->timestamp('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_matter_requests');
    }
};
