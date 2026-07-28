<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_income_records — FirmsVault Live Integrations,
 * Checkpoint 4 (checkpoint4-design-workspace-and-admin-ui.md §1.2;
 * checkpoint4-combined-design.md §1.1.3/§7). Sourced from
 * `/credit/bank_income/get` (Plaid's Bank Income product) — see
 * `App\Integrations\Providers\Plaid\PlaidProvider::pull()`'s `Income`
 * branch.
 *
 * `income_stream_hash` is a SYNTHESIZED id
 * (`hash('sha256', $incomeStreamId)`), not a Plaid-native stable id —
 * Plaid's Income product does not expose one single stable per-stream
 * identifier the way Transactions does (a disclosed gap,
 * checkpoint4-design-plaid-provider-core.md §9.3's own table). This
 * column is also the `external_id` `PlaidProvider::pull()` returns for
 * this resource type.
 *
 * Direct `BelongsToTenant` + FORCE RLS, standard shape. IMMUTABILITY:
 * copies the `DocumentHash` `booted()`-guard idiom — see
 * `financial_evidence_bank_accounts`' own migration docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_income_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('income_stream_hash', 64);
            $table->string('category')->nullable();
            $table->string('pay_frequency')->nullable();
            $table->json('summary_json')->nullable();

            $table->json('raw_json');

            $table->timestamps();

            $table->unique(['firm_integration_id', 'income_stream_hash']);
            $table->index(['firm_id', 'firm_integration_id']);

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_income_records');
    }
};
