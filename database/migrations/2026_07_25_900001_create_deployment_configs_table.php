<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deployment_configs — one row per firm operating in dedicated/private
 * mode. isolated_database/isolated_storage are DECLARATIONS only — no
 * real database or storage provisioning happens anywhere in Phase 16
 * (approved implementation boundary). The four
 * trust_iolta_disabled_* columns are the firm-acknowledgment half of
 * approved decision #2 (operating-only dedicated law-firm trust/IOLTA
 * disabled posture) — the platform-admin-approval half lives entirely
 * in the EXISTING high_risk_change_requests table via a new
 * HighRiskChangeType case; no ninth table was created for this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_configs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->unique()->constrained('firms')->cascadeOnDelete();

            $table->string('custom_domain')->nullable();
            $table->boolean('isolated_database')->default(false);
            $table->boolean('isolated_storage')->default(false);
            $table->json('custom_retention_policy_json')->nullable();
            $table->json('custom_support_access_json')->nullable();
            $table->json('custom_compliance_settings_json')->nullable();
            $table->string('boot_check_status')->default('not_yet_run');

            $table->timestamp('trust_iolta_disabled_acknowledged_at')->nullable();
            $table->foreignId('trust_iolta_disabled_acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('trust_iolta_disabled_acknowledgment_text')->nullable();
            $table->string('trust_iolta_disabled_acknowledgment_version')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_configs');
    }
};
