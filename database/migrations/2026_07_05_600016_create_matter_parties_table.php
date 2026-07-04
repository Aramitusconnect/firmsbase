<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_parties — no firm_id column of its own (per the master
 * plan's entity catalog: id; matter_id; party_id; relationship_type;
 * is_opposing; is_related; created_at). Scoped transitively through
 * matter_id -> matters.firm_id, same pattern as
 * ActivationChecklistItem from Phase 1 — no BelongsToTenant on the
 * model, no RLS policy of its own.
 *
 * relationship_type is a freeform string, not a rigid enum:
 * practice-area templates define what relationship types matter
 * (petitioner, beneficiary, opposing counsel, witness, ...), not
 * platform code (project rule: immigration-specific logic lives in
 * templates).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_parties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();

            $table->string('relationship_type')->nullable();
            $table->boolean('is_opposing')->default(false);
            $table->boolean('is_related')->default(false);

            $table->timestamps();

            $table->unique(['matter_id', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_parties');
    }
};
