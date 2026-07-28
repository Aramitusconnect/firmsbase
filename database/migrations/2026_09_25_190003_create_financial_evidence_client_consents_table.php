<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_client_consents — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §4.6;
 * checkpoint4-combined-design.md §1.4). The CLIENT'S ACTUAL DECISION in
 * response to a `financial_evidence_matter_requests` row: which
 * products were granted or declined, when, from what IP — the consent
 * EVENT itself (request -> consent -> authorization). One row per
 * decision (a later re-consent after a decline creates a NEW row,
 * never edits the old one — matches this checkpoint's own append-only
 * evidentiary discipline for anything client-decision-shaped).
 *
 * `granted_products_json = []` + `declined_at` set records a decline
 * (checkpoint4-design-workspace-and-admin-ui.md §4.7) — the documented
 * trigger for the Upload Fallback path.
 *
 * `firm_integration_id` is nullable — a decline has no connection to
 * attach to yet.
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape (blanket
 * rule, checkpoint4-design-workspace-and-admin-ui.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_client_consents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('matter_request_id')->nullable()->constrained('financial_evidence_matter_requests')->nullOnDelete();
            $table->foreignId('firm_integration_id')->nullable(); // bare column; composite FK below

            $table->json('granted_products_json');
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id']);
            $table->index(['firm_id', 'client_id']);

            // No onDelete action (default RESTRICT) — unlike the
            // materializer tables' own required, non-nullable
            // firm_integration_id, this column is nullable (a decline
            // has no connection yet) so a composite nullOnDelete/
            // cascadeOnDelete action is not applicable here (it would
            // attempt to null/cascade the co-located NOT NULL firm_id
            // column too). ProviderConnectionService::disconnect()
            // never deletes a firm_integrations row (only transitions
            // its status), so this FK is never actually exercised in
            // practice.
            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_client_consents');
    }
};
