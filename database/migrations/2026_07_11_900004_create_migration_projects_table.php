<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * migration_projects — created before import_batches in migration
 * order since import_batches.migration_project_id references this
 * table. Source types are guides/labels only (project rule) — no real
 * external API call is ever made for clio_export/mycase_export/
 * docketwise_export/dropbox_folder/google_drive_folder. created_by is
 * split (firm_users vs platform_admins) since a migration project is
 * plausibly initiated either by the firm's own staff (self-service) or
 * by a platform implementation specialist assisting onboarding
 * (mirrors Phase 7's ImplementationProject.assigned_to pattern).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->string('source_type');
            $table->string('status')->default('draft');
            $table->text('notes')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('created_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->foreignId('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_projects');
    }
};
