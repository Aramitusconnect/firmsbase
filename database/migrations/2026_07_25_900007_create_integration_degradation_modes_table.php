<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_degradation_modes — platform-level reference data (not
 * firm-scoped, no firm_id column, no BelongsToTenant on the model).
 * Exactly 4 rows, one per IntegrationType, seeded by an idempotent data
 * migration. Declaration-only (approved decision #1) — no Stripe/
 * email/virus-scan/telemetry call site is wired to consult this table
 * in Phase 16.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_degradation_modes', function (Blueprint $table) {
            $table->id();

            $table->string('integration_type')->unique();
            $table->string('degraded_behavior');
            $table->text('description')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_degradation_modes');
    }
};
