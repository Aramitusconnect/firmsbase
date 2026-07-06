<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * private_enterprise_settings — one row per private-enterprise firm.
 * The requires_* booleans are DECLARATIONS of what this deployment
 * needs (custom domain, isolated database/storage) — no real
 * provisioning of any of them happens in Phase 16.
 * telemetry_prohibited drives DeploymentHealthEnvelopeService's
 * offline-report fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_enterprise_settings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->unique()->constrained('firms')->cascadeOnDelete();

            $table->boolean('requires_custom_domain')->default(false);
            $table->boolean('requires_isolated_database')->default(false);
            $table->boolean('requires_isolated_storage')->default(false);
            $table->boolean('telemetry_prohibited')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_enterprise_settings');
    }
};
