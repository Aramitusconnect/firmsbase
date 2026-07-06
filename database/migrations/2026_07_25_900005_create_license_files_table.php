<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * license_files — the signed offline license artifact record. Extends
 * the EXISTING Phase 6 firm_licenses/org_licenses (project rule 1) —
 * license_key here must match the linked firm_licenses/org_licenses
 * row's license_key; this is not a second license system. Supports
 * both a firm-level artifact (firm_id + firm_license_id) and an
 * organization-level artifact (organization_id + org_license_id)
 * (approved decision #4). A CHECK constraint (Postgres, matching this
 * codebase's existing Postgres-first posture, mirrors the partial
 * unique index pattern used for webhook_secrets/firm_ai_provider_keys)
 * enforces that EXACTLY ONE owner path is populated per row at the
 * database layer, not just in application code — the same
 * defense-in-depth discipline used throughout this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('firm_license_id')->nullable()->constrained('firm_licenses')->cascadeOnDelete();
            $table->foreignId('org_license_id')->nullable()->constrained('org_licenses')->cascadeOnDelete();

            $table->string('licensed_to');
            $table->string('license_key');
            $table->text('signed_payload');
            $table->text('signature');
            $table->string('signature_algorithm');
            $table->string('deployment_mode');

            $table->timestamp('expires_at');
            $table->unsignedInteger('grace_period_days')->default(0);

            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('organization_id');
            $table->index('license_key');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE license_files ADD CONSTRAINT license_files_exactly_one_owner_path CHECK (
                (firm_id IS NOT NULL AND firm_license_id IS NOT NULL AND organization_id IS NULL AND org_license_id IS NULL)
                OR
                (organization_id IS NOT NULL AND org_license_id IS NOT NULL AND firm_id IS NULL AND firm_license_id IS NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('license_files');
    }
};
