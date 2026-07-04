<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * backup_restore_tests — recorded evidence of a restore drill, never a
 * real infrastructure backup/restore performed by this application
 * (project rule) — BackupRestoreTestService persists the result of a
 * BackupRestoreDrillRunner (fakeable; FakeBackupRestoreDrillRunner is
 * the only implementation this phase, same pattern as Phase 4's
 * VirusScanner). firm_id is nullable: null for a platform-wide
 * infrastructure drill, non-null when the drill specifically verified
 * one firm's tenant_settings recovery. rpo_target_seconds/
 * rto_target_seconds default to the master plan's controls (24h/8h
 * maximum before paid launch unless a stricter target is approved).
 * components_verified_json is a plain-string array (database_records,
 * documents, app_configuration, queues, tenant_settings, critical_logs)
 * — a checklist of what this run actually verified, not a state
 * machine, so no enum is used for its elements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_restore_tests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();

            $table->string('status')->default('in_progress');
            $table->json('components_verified_json')->nullable();

            $table->unsignedInteger('rpo_target_seconds')->default(86400);
            $table->unsignedInteger('rto_target_seconds')->default(28800);
            $table->unsignedInteger('rpo_actual_seconds')->nullable();
            $table->unsignedInteger('rto_actual_seconds')->nullable();

            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_restore_tests');
    }
};
