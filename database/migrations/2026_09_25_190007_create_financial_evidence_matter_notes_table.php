<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * financial_evidence_matter_notes — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-design-workspace-and-admin-ui.md §1.8). Append-only
 * Attorney Notes on the Financial Evidence Workspace — no `updated_at`
 * edit path, matching `TrustLedgerEntry`'s `$timestamps = false`/
 * `booted()`-guard idiom exactly since a note is evidentiary once
 * written. Has NO Client Portal read path anywhere in this design
 * (checkpoint4-combined-design.md §9.4/§4.12) — the omission is
 * structural: no Client-Portal Filament class ever references this
 * table.
 *
 * Direct `BelongsToTenant` + FORCE RLS, the standard shape (blanket
 * rule, checkpoint4-design-workspace-and-admin-ui.md §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_evidence_matter_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('author_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->text('body');

            $table->timestamp('created_at');

            $table->index(['firm_id', 'matter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_evidence_matter_notes');
    }
};
