<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * fleet_migration_instance_status — one row per (fleet_migration_run,
 * firm) pair. unique(fleet_migration_run_id, firm_id) — a firm can
 * appear at most once per run. Skipped is written for any instance
 * still Pending at the moment an earlier instance's Failed status
 * halts the run (project rule: failure halts remaining pending
 * instances).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_migration_instance_status', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fleet_migration_run_id')->constrained('fleet_migration_runs')->cascadeOnDelete();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('status')->default('pending');
            $table->string('applied_version')->nullable();
            $table->text('error_detail')->nullable();

            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['fleet_migration_run_id', 'firm_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_migration_instance_status');
    }
};
