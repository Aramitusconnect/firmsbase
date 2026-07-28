<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_reconciliation_candidates — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). EXPLICITLY NEVER
 * AUTO-POSTING TO THE TRUST LEDGER; display-only, attorney-decision-
 * driven. `trust_ledger_entry_id` is a READ-ONLY FK reference — never a
 * write path, the column exists only so a human can see "this Plaid
 * transaction plausibly corresponds to this existing ledger entry."
 * The one action this queue offers ("Confirm as ledger match," an
 * attorney-only action) transitions `status` on THIS table only — never
 * writes to `trust_ledger_entries` or any `Trust*` table.
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape (blanket
 * rule, checkpoint4-design-workspace-and-admin-ui.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_reconciliation_candidates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('financial_evidence_transactions')->cascadeOnDelete();

            // READ-ONLY reference — no FK constraint to trust_ledger_entries
            // deliberately (this table lives under
            // App\Integrations\Services, not app/Services/Trust*.php, and
            // must never become a write dependency of the trust domain,
            // nor vice versa — a bare, unconstrained bigint keeps the two
            // domains structurally decoupled at the schema level too, not
            // merely at the application-code level).
            $table->unsignedBigInteger('trust_ledger_entry_id')->nullable();

            $table->string('match_confidence'); // low|medium|high
            $table->string('status')->default('candidate'); // candidate|confirmed_match|rejected
            $table->foreignId('reviewed_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_reconciliation_candidates');
    }
};
