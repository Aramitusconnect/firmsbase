<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * demo_events — platform sales demos to prospective firms. Distinct
 * from Phase 2's consultations (a firm's own client consultations) —
 * different table, different owner, no collision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('held_at')->nullable();
            $table->string('status')->default('scheduled');
            $table->foreignId('conducted_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('opportunity_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_events');
    }
};
