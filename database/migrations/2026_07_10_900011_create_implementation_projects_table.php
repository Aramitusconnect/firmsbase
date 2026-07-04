<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * implementation_projects — one per firm (unique firm_id), mirroring
 * activation_checklists' one-per-firm pattern from Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('implementation_projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->string('status')->default('not_started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('go_live_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('success_review_due_at')->nullable();
            $table->timestamp('success_review_completed_at')->nullable();

            $table->timestamps();

            $table->unique('firm_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('implementation_projects');
    }
};
