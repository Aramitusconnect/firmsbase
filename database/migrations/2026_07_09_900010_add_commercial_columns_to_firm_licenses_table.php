<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: adds org_license_id/plan_id/deployment_mode/
 * customer_type/billing_mode to the EXISTING firm_licenses table
 * (created in Phase 1). Does not recreate firm_licenses. Phase 1's
 * migration comment explicitly deferred these columns to Phase 6.
 * deployment_mode/customer_type reuse the EXISTING DeploymentMode/
 * CustomerType enums (no new enums for these two).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_licenses', function (Blueprint $table) {
            $table->foreignId('org_license_id')->nullable()->after('billing_account_id')
                ->constrained('org_licenses')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->after('org_license_id')
                ->constrained('plans')->nullOnDelete();
            $table->string('deployment_mode')->nullable()->after('license_status');
            $table->string('customer_type')->nullable()->after('deployment_mode');
            $table->string('billing_mode')->nullable()->after('customer_type');
        });
    }

    public function down(): void
    {
        Schema::table('firm_licenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('org_license_id');
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn(['deployment_mode', 'customer_type', 'billing_mode']);
        });
    }
};
