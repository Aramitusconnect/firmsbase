<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_matter_authorizations — FirmsVault Live
 * Integrations, Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.4/§4.6, originally
 * named `financial_evidence_matter_grants`; renamed per
 * checkpoint4-combined-design.md §1.4's binding naming pass — drops the
 * colliding `_grants` suffix shared with the unrelated
 * `client_portal_matter_grants` portal-visibility table).
 *
 * The resulting, currently-in-effect binding: which `firm_integrations`
 * (Plaid connections) back this matter's financial evidence, plus the
 * currently-authorized date range — derived from a
 * `financial_evidence_client_consents` row, re-editable only through a
 * renewal action (never silently widened by any sync job). One row per
 * (matter, firm_integration) pair; a renewal supersedes the prior row
 * (never edited in place — matches this checkpoint's own evidentiary
 * append-only discipline for anything derived from client consent).
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape (blanket
 * rule, checkpoint4-design-workspace-and-admin-ui.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_matter_authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint
            $table->foreignId('consent_id')->nullable()->constrained('financial_evidence_client_consents')->nullOnDelete();

            $table->date('authorized_date_range_start')->nullable();
            $table->date('authorized_date_range_end')->nullable();

            $table->timestamp('superseded_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id']);

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_matter_authorizations');
    }
};
