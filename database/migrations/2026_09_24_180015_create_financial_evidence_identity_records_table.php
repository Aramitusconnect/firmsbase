<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_identity_records — FirmsVault Live Integrations,
 * Checkpoint 4 (checkpoint4-design-workspace-and-admin-ui.md §1.2;
 * checkpoint4-combined-design.md §1.1.3/§7). Sourced from
 * `/identity/get` — see
 * `App\Integrations\Providers\Plaid\PlaidProvider::pull()`'s `Identity`
 * branch. Only the name array is guaranteed populated by Plaid; the
 * other three arrays may legitimately be empty depending on what the
 * institution exposes (`checkpoint4-plaid-official-documentation-research.md`
 * §3) — all four columns are therefore nullable JSON, never NOT NULL.
 *
 * Direct `BelongsToTenant` + FORCE RLS, standard shape. IMMUTABILITY:
 * copies the `DocumentHash` `booted()`-guard idiom — see
 * `financial_evidence_bank_accounts`' own migration docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_identity_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint

            $table->string('plaid_account_id');
            $table->json('owner_names_json')->nullable();
            $table->json('owner_emails_json')->nullable();
            $table->json('owner_phones_json')->nullable();
            $table->json('owner_addresses_json')->nullable();

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
        Schema::dropIfExists('financial_evidence_identity_records');
    }
};
