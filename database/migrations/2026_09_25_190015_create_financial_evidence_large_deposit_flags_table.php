<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_large_deposit_flags — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.7). Written by
 * `FinancialEvidenceLargeDepositDetectionService`, which flags any
 * `financial_evidence_transactions` row whose `amount_cents` exceeds
 * the resolved threshold (from
 * `financial_evidence_large_deposit_thresholds`, this checkpoint's
 * sibling Global table). Provenance = `FirmsVaultObservation`,
 * display-only. Same dismiss/confirm action pair as
 * `financial_evidence_duplicate_transfer_flags`.
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape (blanket
 * rule, checkpoint4-design-workspace-and-admin-ui.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_large_deposit_flags', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('financial_evidence_transactions')->cascadeOnDelete();

            $table->unsignedBigInteger('threshold_cents_applied');
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
        Schema::dropIfExists('financial_evidence_large_deposit_flags');
    }
};
