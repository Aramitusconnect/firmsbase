<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_transaction_reviews — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.6.1). Deliberately
 * SEPARATE from the immutable `financial_evidence_transactions` row
 * (provenance split: the transaction fact is `ProviderSuppliedFact`;
 * the review is `AttorneyConfirmedClassification`). One row per
 * transaction PER REVIEW EVENT (append-only — a re-review creates a
 * new row rather than editing the old one, preserving who said what and
 * when).
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape (blanket
 * rule, checkpoint4-design-workspace-and-admin-ui.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_transaction_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained('financial_evidence_transactions')->cascadeOnDelete();
            $table->foreignId('reviewed_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->timestamp('reviewed_at');
            $table->boolean('flagged')->default(false);
            $table->string('flag_reason')->nullable();
            $table->string('classification')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'transaction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_transaction_reviews');
    }
};
