<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_liabilities — FirmsVault Live Integrations,
 * Checkpoint 4 (checkpoint4-design-workspace-and-admin-ui.md §1.2;
 * checkpoint4-combined-design.md §1.1.3/§7). Sourced from
 * `/liabilities/get` — see
 * `App\Integrations\Providers\Plaid\PlaidProvider::pull()`'s
 * `Liability` branch.
 *
 * `type_specific_json` is NEVER force-merged into one generic shape —
 * per `checkpoint4-design-plaid-provider-core.md` §9.3's explicit
 * instruction, Plaid's three liability types (credit/mortgage/student)
 * have genuinely different field sets; this column stores whichever
 * type-specific object Plaid actually returned, verbatim.
 *
 * Direct `BelongsToTenant` + FORCE RLS, standard shape. IMMUTABILITY:
 * copies the `DocumentHash` `booted()`-guard idiom — see
 * `financial_evidence_bank_accounts`' own migration docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_liabilities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('plaid_account_id');
            $table->string('liability_type'); // credit|mortgage|student
            $table->json('type_specific_json');

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
        Schema::dropIfExists('financial_evidence_liabilities');
    }
};
