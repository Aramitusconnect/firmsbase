<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * org_licenses — an organization's master license/plan. Reuses the
 * EXISTING LicenseStatus enum as-is (no new OrgLicenseStatus) — the
 * same 12-state commercial lifecycle applies at the organization level
 * as at the firm level. Member firms inherit entitlements from this
 * license's plan via EntitlementPlanSyncService writing
 * EntitlementSource::OrgInherited rows into the EXISTING
 * firm_entitlements table — no second entitlement system.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_licenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->foreignId('billing_account_id')->nullable()->constrained('billing_accounts')->nullOnDelete();

            $table->string('license_key')->unique();
            $table->string('license_status')->default('trial');

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('renews_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('organization_id');
            $table->index('license_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_licenses');
    }
};
