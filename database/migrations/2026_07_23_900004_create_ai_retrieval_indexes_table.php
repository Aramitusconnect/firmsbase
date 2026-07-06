<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_retrieval_indexes — records that a structurally isolated
 * namespace/partition has been provisioned for a firm (project rules
 * 13/14: dedicated namespace/partition per firm, never a shared index
 * filtered only by metadata). Phase 15 has no real vector/search
 * backend — this table is foundation/record-keeping only: it proves
 * the isolation CONTRACT (one namespace_identifier per firm, unique,
 * never reused), not a working retrieval engine. A future
 * retrieval-backend phase would provision the real store keyed by this
 * identifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_retrieval_indexes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->unique()->constrained('firms')->cascadeOnDelete();
            $table->string('namespace_identifier')->unique();
            $table->string('status');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_retrieval_indexes');
    }
};
