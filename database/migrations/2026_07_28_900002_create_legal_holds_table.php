<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * legal_holds — approved decision #9: explicit nullable FKs per fixed
 * level (firm/client/matter/document) rather than a single polymorphic
 * subject pair, since these levels are fixed and important. scope_type
 * declares which single level this row applies to; exactly one of
 * client_id/matter_id/document_id is set for Client/Matter/Document
 * scope, all three null for Firm scope (firm_id alone is enough).
 *
 * Blocks deletion and key destruction only — never export/archive
 * (Master Plan edge-case table, page 50) — that rule lives in
 * LegalHoldService, not as a DB constraint here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_holds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('scope_type');
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->cascadeOnDelete();

            $table->text('reason');
            $table->string('status')->default('active');

            $table->nullableMorphs('placed_by');
            $table->timestamp('placed_at');

            $table->nullableMorphs('released_by');
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index(['firm_id', 'scope_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_holds');
    }
};
