<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firms — the operating tenant. customer_type and deployment_mode live
 * here (master plan Section 32 entity catalog).
 *
 * billing_account_id is nullable to allow a firm to exist
 * pre-activation. The transition guard that REQUIRES billing_account_id
 * before a firm can leave draft/onboarding is NOT implemented in this
 * migration or in the Firm model — that enforcement belongs to
 * ActivationChecklistService.
 *
 * activation_status: draft | onboarding | activated only. No
 * suspended/archived values here — those belong to
 * firm_licenses.license_status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firms', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('organization_id')
                ->nullable()
                ->constrained('organizations')
                ->nullOnDelete();

            $table->foreignId('billing_account_id')
                ->nullable()
                ->constrained('billing_accounts')
                ->nullOnDelete();

            $table->string('name');
            $table->string('legal_name')->nullable();

            $table->string('customer_type');
            $table->string('deployment_mode')->default('saas');

            $table->string('primary_country')->nullable();
            $table->string('primary_state')->nullable();
            $table->string('default_timezone')->default('UTC');
            $table->string('default_currency', 3)->default('USD');
            $table->string('data_region')->nullable();

            $table->string('activation_status')->default('draft');

            $table->timestamps();

            $table->index('organization_id');
            $table->index('billing_account_id');
            $table->index('activation_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firms');
    }
};
