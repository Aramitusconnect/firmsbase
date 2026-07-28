<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_investment_records — FirmsVault Live Integrations,
 * Checkpoint 4 (checkpoint4-design-workspace-and-admin-ui.md §1.2;
 * checkpoint4-combined-design.md §1.1.3/§7). Sourced from
 * `/investments/holdings/get` + `/investments/transactions/get`,
 * merged into one `items` array with a `record_type` discriminator —
 * see `App\Integrations\Providers\Plaid\PlaidProvider::pull()`'s
 * `Investment` branch.
 *
 * `plaid_external_id` mirrors exactly whichever id
 * `PlaidProvider::pull()` used as this row's `external_id`
 * (`security_id` for a `holding` row, `investment_transaction_id` for a
 * `transaction` row) — kept as its own column so a uniqueness
 * constraint can be expressed without depending on which of the two
 * nullable, type-specific columns is populated.
 *
 * Direct `BelongsToTenant` + FORCE RLS, standard shape. IMMUTABILITY:
 * copies the `DocumentHash` `booted()`-guard idiom — see
 * `financial_evidence_bank_accounts`' own migration docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_investment_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('record_type'); // holding|transaction
            $table->string('plaid_external_id');
            $table->string('plaid_security_id')->nullable();
            $table->string('plaid_investment_transaction_id')->nullable();

            $table->json('raw_json');

            $table->timestamps();

            $table->unique(['firm_integration_id', 'record_type', 'plaid_external_id']);
            $table->index(['firm_id', 'firm_integration_id']);

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_investment_records');
    }
};
