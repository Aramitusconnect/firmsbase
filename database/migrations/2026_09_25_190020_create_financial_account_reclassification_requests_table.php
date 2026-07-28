<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_account_reclassification_requests — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §5;
 * checkpoint4-combined-design.md §9.5). The concrete pending-approval
 * UI state `checkpoint4-pre-construction-inventory.md` §5/§8 flags as
 * needing net-new design — styled after
 * `TrustHighRiskAdjustmentService`'s
 * `AdjustmentRequested` -> `AdjustmentFirstApproved` ->
 * `AdjustmentSecondApproved` append-only pattern, but (unlike that
 * service) this domain has NO existing `*_approval_events` table to
 * reuse, so this table itself carries the full request/first-approve/
 * second-approve state machine as ordinary MUTABLE status-column state
 * (matching `IntegrationConflict`'s shape — this is workflow state, not
 * evidence, per checkpoint4-design-workspace-and-admin-ui.md §6's own
 * "flag/queue/request tables use ordinary mutable status-column state"
 * rule).
 *
 * `reason` is mandatory (mirrors `EntitlementOverrideService::assertValidOverride()`'s
 * "a reason is mandatory" rule). The actual write to
 * `financial_evidence_bank_accounts.classification` happens ONLY at
 * the second-approval transition
 * (`FinancialAccountReclassificationService::approve()`'s sole write
 * point) — never on this table's own rows, and never before both
 * approvals are recorded.
 *
 * Direct `BelongsToTenant` + FORCE RLS — explicitly stated, no gap
 * here (checkpoint4-design-workspace-and-admin-ui.md §5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_account_reclassification_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('financial_evidence_bank_accounts')->cascadeOnDelete();

            $table->string('requested_classification');
            $table->string('previous_classification')->nullable();
            $table->foreignId('requested_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->timestamp('requested_at');
            $table->text('reason');

            $table->foreignId('first_approved_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable();

            $table->foreignId('second_approved_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable();

            $table->foreignId('rejected_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();

            $table->string('status')->default('pending'); // pending|first_approved|approved|rejected
            $table->uuid('correlation_uuid');

            $table->timestamps();

            $table->index(['firm_id', 'bank_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_reclassification_requests');
    }
};
