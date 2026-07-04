<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * conflict_check_runs — one run per conflict-check attempt for a
 * matter. scope resolves from Organization::conflict_scope at run
 * time (firm-scoped default; organization requires explicit opt-in) —
 * see ConflictCheckService. A matter cannot leave
 * conflict_check_required/conflict_review without a completed run with
 * no unresolved results (MatterOpeningService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conflict_check_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('pending');
            $table->string('scope')->default('firm');
            $table->json('searched_terms_json')->nullable();
            $table->unsignedInteger('result_count')->default(0);
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('matter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conflict_check_runs');
    }
};
