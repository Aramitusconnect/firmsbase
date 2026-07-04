<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_roles — a grant/assignment table over the fixed
 * PlatformRoleCode enum. platform_admins is the only platform-staff
 * identity table (Phase 1); this migration does not create a second
 * one. No uuid — grant rows are looked up only via
 * (platform_admin_id, role_code), never addressed individually (mirrors
 * Phase 6's LicenseEvent precedent). No unique constraint on
 * (platform_admin_id, role_code): PlatformRoleService enforces "one
 * active grant per admin+role" at the app layer so a revoked role can
 * be re-granted later without a unique-index conflict on old rows.
 * Not RLS-prepared and does not use BelongsToTenant — platform staff
 * roles are cross-firm by design (approved Phase 7 decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_roles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->string('role_code');

            $table->foreignId('granted_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index('platform_admin_id');
            $table->index('role_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_roles');
    }
};
