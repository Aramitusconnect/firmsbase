<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * offboarding_requests — firm-level state machine sequencing export ->
 * retention clearance -> legal-hold clearance -> ready-for-deletion ->
 * completed. This table alone does not delete or destroy anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('status')->default('requested');
            $table->text('reason');

            $table->foreignId('requested_by_platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancelled_reason')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_requests');
    }
};
