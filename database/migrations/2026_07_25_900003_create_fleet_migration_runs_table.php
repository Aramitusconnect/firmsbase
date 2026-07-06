<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fleet_migration_runs — one row per simulated fleet-wide migration
 * rollout (project rule: simulated/foundation only in Phase 16 — no
 * real migration is ever executed, no shell/process execution, no
 * real migrations across external servers). Not firm-owned (a single
 * run spans many firms); per-instance detail lives in
 * fleet_migration_instance_status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_migration_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('migration_identifier');
            $table->string('status')->default('pending');
            $table->foreignId('initiated_by')->constrained('users')->cascadeOnDelete();
            $table->text('halted_reason')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_migration_runs');
    }
};
