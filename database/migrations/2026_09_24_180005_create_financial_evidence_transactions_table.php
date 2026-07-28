<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_transactions — FirmsVault Live Integrations,
 * Checkpoint 4 (checkpoint4-design-workspace-and-admin-ui.md §1.2;
 * checkpoint4-combined-design.md §1.1.3/§7). Sourced from
 * `/transactions/sync` — see
 * `App\Integrations\Providers\Plaid\PlaidProvider::pull()`'s
 * `Transaction` branch / `pullTransactions()`.
 *
 * Direct `BelongsToTenant` + FORCE RLS, standard shape — see the
 * companion `prepare_row_level_security_and_force_rls_on_*` migration.
 *
 * `bank_account_id` is a NULLABLE, best-effort local FK to
 * `financial_evidence_bank_accounts` — Plaid's Transactions and Auth
 * products are pulled independently (separate `ResourceType` cursors,
 * no ordering guarantee between them), so a Transaction may be
 * materialized before its owning account has ever been pulled.
 * `plaid_account_id` (the raw Plaid identifier) is always stored
 * regardless, so a later backfill pass could resolve it — not built in
 * this checkpoint, a disclosed, narrow limitation of this materializer.
 *
 * `category_json` stores Plaid's `personal_finance_category` object
 * verbatim (primary/detailed/confidence) — never flattened into a
 * single string, since the object shape itself is part of what a future
 * UI needs to render.
 *
 * IMMUTABILITY: copies the `DocumentHash` `booted()`-guard idiom — see
 * `financial_evidence_bank_accounts`' own migration docblock for the
 * full reasoning (identical here). A transaction Plaid later reports as
 * `modified` (e.g. pending -> posted) must not silently overwrite what
 * the firm saw yesterday.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('plaid_transaction_id');
            $table->string('plaid_account_id');
            $table->foreignId('bank_account_id')->nullable()
                ->constrained('financial_evidence_bank_accounts')->nullOnDelete();

            $table->bigInteger('amount_cents');
            $table->string('iso_currency_code', 8)->nullable();
            $table->date('transaction_date');
            $table->date('posted_date')->nullable();
            $table->string('merchant_name')->nullable();
            $table->json('category_json')->nullable();
            $table->boolean('pending')->default(false);
            $table->timestamp('provider_retrieved_at')->nullable();

            $table->json('raw_json');

            $table->timestamps();

            $table->unique(['firm_integration_id', 'plaid_transaction_id']);
            $table->index(['firm_id', 'firm_integration_id']);
            $table->index(['firm_integration_id', 'bank_account_id']);

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_transactions');
    }
};
