<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_snapshots — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.9). Immutable, one
 * row per snapshot-creation event, columns matching the spec's exact
 * required-field list one-to-one (see the table in that section):
 * authorized source/accounts/date-range, retrieved records, retrieval
 * timestamp, redacted Plaid request reference, source product, report
 * version, checksum (with a distinct `checksum_source` so a reviewer
 * can tell "Plaid-supplied" from "FirmsVault-computed" apart),
 * generating actor, matter, consent reference, limitations text.
 *
 * `App\Filament\Firm\Resources\MatterResource\...\FinancialEvidenceReportsPanel`'s
 * export action MUST originate from an existing row here, never a live
 * re-query — this is the only way an export's fields stay consistent
 * with what a human actually reviewed.
 *
 * Copies the `DocumentHash`/`TrustLedgerEntry` `booted()`-guard
 * immutability idiom — an existing row can never be updated or
 * deleted.
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape (blanket
 * rule, checkpoint4-design-workspace-and-admin-ui.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('generated_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();
            $table->foreignId('consent_id')->nullable()->constrained('financial_evidence_client_consents')->nullOnDelete();

            $table->json('authorized_source_json');
            $table->json('authorized_account_ids_json');
            $table->date('date_range_start')->nullable();
            $table->date('date_range_end')->nullable();
            $table->json('retrieved_record_refs_json');
            $table->timestamp('provider_retrieved_at')->nullable();
            $table->string('redacted_request_reference')->nullable();
            $table->string('source_product');
            $table->unsignedInteger('report_version')->default(1);
            $table->string('checksum', 64)->nullable();
            $table->string('checksum_source')->nullable(); // 'plaid_supplied'|'firmsvault_computed'
            $table->text('limitations_text');

            $table->timestamp('created_at');

            $table->index(['firm_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_snapshots');
    }
};
