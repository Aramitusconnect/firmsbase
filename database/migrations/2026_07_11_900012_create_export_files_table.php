<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * export_files — belongs to export_jobs (project rule: "export files
 * must belong to export jobs"). simulated_storage_path is metadata
 * only — no real ZIP file is ever written, no real external storage
 * movement occurs (both forbidden items). Every export_files row is
 * scoped transitively through export_job_id (which itself carries
 * firm_id) — this is what makes "export must never include another
 * firm's data" testable at the table level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('export_job_id')->constrained('export_jobs')->cascadeOnDelete();

            $table->string('file_label');
            $table->string('simulated_storage_path');
            $table->unsignedBigInteger('size_bytes_estimate')->nullable();

            $table->string('status')->default('pending');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('export_job_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_files');
    }
};
