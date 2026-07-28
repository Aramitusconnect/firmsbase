<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_bank_accounts — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.2, schema authoritative
 * source; checkpoint4-combined-design.md §1.1.3/§7, implementation
 * ownership reassigned to the Plaid provider-core phase). One of seven
 * new materializer tables — the first-ever local domain-model target
 * for a `ResourceType` this codebase has never needed a "materialize a
 * new local record from an external pull" hook for
 * (`App\Integrations\Support\FinancialEvidenceMaterializerService`).
 * Sourced from `/auth/get` (Plaid's Auth product) — see
 * `App\Integrations\Providers\Plaid\PlaidProvider::pull()`'s
 * `BankAccount` branch.
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape every other
 * `firm_integration_id`-keyed table in this mission uses — see the
 * companion `prepare_row_level_security_and_force_rls_on_*` migration.
 *
 * IMMUTABILITY: this table copies the `DocumentHash`/`TrustLedgerEntry`
 * `booted()`-guard idiom (`App\Models\DocumentHash`) — an existing row
 * can never be updated or deleted at the model layer. A resync that
 * detects a changed external object creates a NEW row and tombstones
 * the OLD `integration_external_mappings` row that pointed to the
 * superseded one (never an in-place update here) — the correct
 * provenance-preserving behavior for provider-supplied fact data.
 *
 * `raw_json` carries the full, unmodified Plaid response object for
 * this account — `integration_sync_items.payload_hash` is a hash only,
 * insufficient for any future UI to display more than a fingerprint.
 *
 * `classification` is deliberately a plain, unconstrained, nullable
 * string here (never a DB-level enum, never cast to an app-level enum
 * on this migration/model) — the owning
 * `App\Integrations\Enums\FinancialAccountClassification` enum and its
 * write path (`FinancialAccountReclassificationService`) belong to the
 * Financial Evidence Workspace UI track, out of this file's scope; this
 * column exists only so that future write path has somewhere to write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('plaid_account_id');
            $table->string('account_name')->nullable();
            $table->string('account_subtype')->nullable();
            $table->string('mask', 8)->nullable();
            $table->string('classification')->nullable();

            $table->json('raw_json');

            $table->timestamps();

            $table->unique(['firm_integration_id', 'plaid_account_id']);
            $table->index(['firm_id', 'firm_integration_id']);

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_bank_accounts');
    }
};
