<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * offboarding_exports — governed wrapper around the EXISTING Phase 8
 * export_jobs/export_files simulated-export foundation (project rule:
 * do not build a second export engine). package_manifest_json is a
 * declared list of data-category strings; no real ZIP/file is ever
 * produced (export_jobs' own simulated_storage_path convention is
 * reused unchanged).
 *
 * deletion_request_id is intentionally a PLAIN nullable bigint with no
 * FK constraint — deletion_requests.offboarding_export_id already
 * references this table with a real FK in the primary direction, and a
 * mutual FK pair would be circular across two CREATE TABLE statements.
 * This mirrors the existing tenant_encryption_keys.destruction_request_id
 * precedent (Phase 1), which is also an intentionally unconstrained
 * forward reference for the same structural reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('offboarding_request_id')->nullable()->constrained('offboarding_requests')->cascadeOnDelete();
            // No FK: deletion_requests does not exist yet at this point
            // in migration order; see class doc comment.
            $table->unsignedBigInteger('deletion_request_id')->nullable();
            $table->foreignId('export_job_id')->nullable()->constrained('export_jobs')->nullOnDelete();

            $table->string('status')->default('pending');
            $table->json('package_manifest_json')->nullable();

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('offboarding_request_id');
            $table->index('deletion_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offboarding_exports');
    }
};
