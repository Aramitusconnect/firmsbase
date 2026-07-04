<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activation_checklists — one per firm. Gates the draft/onboarding ->
 * activated transition alongside the billing_account_id check
 * (ActivationChecklistService enforces both).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_checklists', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->unique()->constrained('firms')->cascadeOnDelete();

            $table->string('status')->default('in_progress');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_checklists');
    }
};
