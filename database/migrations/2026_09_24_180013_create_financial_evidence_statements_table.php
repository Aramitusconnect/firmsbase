<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_statements — FirmsVault Live Integrations,
 * Checkpoint 4 (checkpoint4-design-workspace-and-admin-ui.md §1.2;
 * checkpoint4-combined-design.md §1.1.3/§7). Sourced from
 * `/statements/list` — see
 * `App\Integrations\Providers\Plaid\PlaidProvider::pull()`'s
 * `Statement` branch. US institutions only, per Plaid's own documented
 * geographic limitation (checkpoint4-plaid-official-documentation-research.md
 * §9).
 *
 * `storage_disk`/`storage_path` are nullable — populated only once a
 * later export/download action (`downloadStatement()`, out of this
 * table's own materialization scope) actually persists the binary PDF
 * via this codebase's existing `Document`/`DocumentSecurityService`
 * pointer convention; this materializer never downloads or stores a
 * PDF body itself, only the statement's own metadata row.
 *
 * Direct `BelongsToTenant` + FORCE RLS, standard shape. IMMUTABILITY:
 * copies the `DocumentHash` `booted()`-guard idiom — see
 * `financial_evidence_bank_accounts`' own migration docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_statements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('plaid_statement_id');
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->date('available_date')->nullable();
            $table->string('storage_disk')->nullable();
            $table->string('storage_path')->nullable();

            $table->json('raw_json');

            $table->timestamps();

            $table->unique(['firm_integration_id', 'plaid_statement_id']);
            $table->index(['firm_id', 'firm_integration_id']);

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_statements');
    }
};
