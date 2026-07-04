<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * intake_submissions — matter_id is nullable: intake can begin at the
 * lead/consultation stage before any matter exists. responses_json
 * holds the filled-out answers; Phase 2 does not build a form
 * renderer, only the storage/workflow shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intake_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('intake_template_id')->constrained('intake_templates')->restrictOnDelete();

            $table->string('status')->default('draft');
            $table->json('responses_json')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('client_id');
            $table->index('matter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_submissions');
    }
};
