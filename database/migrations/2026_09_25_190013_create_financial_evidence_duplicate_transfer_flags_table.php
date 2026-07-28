<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_duplicate_transfer_flags — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). Written by
 * `FinancialEvidenceDuplicateTransferDetectionService::evaluate()` —
 * flags pairs of `financial_evidence_transactions` rows across two of
 * the matter's connected accounts with a matching amount within a
 * short window and opposite sign. Provenance = `FirmsVaultObservation`,
 * display-only, never auto-posting anywhere. Row action: "Dismiss"
 * (sets `dismissed_at`/`dismissed_by`, never a delete) or "Confirm as
 * duplicate" (sets `confirmed_at`/`confirmed_by`).
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape (blanket
 * rule, checkpoint4-design-workspace-and-admin-ui.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_duplicate_transfer_flags', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('transaction_id_a')->constrained('financial_evidence_transactions')->cascadeOnDelete();
            $table->foreignId('transaction_id_b')->constrained('financial_evidence_transactions')->cascadeOnDelete();

            $table->timestamp('detected_at');
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_duplicate_transfer_flags');
    }
};
