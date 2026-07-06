<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * deployment_health_checks — append-only, per-instance health envelope
 * log. A DIFFERENT table from Phase 5's health_checks (SaaS-internal
 * infrastructure monitoring) — this one is the minimum health envelope
 * contract for dedicated/private deployments (anonymized heartbeat,
 * version, migration status). status reuses HealthCheckStatus's exact
 * case values (no second status vocabulary). reported_via is
 * offline_report whenever private_enterprise_settings.telemetry_prohibited
 * is true for the firm — the row is still written locally either way,
 * "offline" means no outbound telemetry call, not that health checking
 * stops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deployment_health_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();

            $table->timestamp('heartbeat_at');
            $table->string('version');
            $table->string('migration_status')->nullable();
            $table->string('status');
            $table->string('reported_via')->default('live');
            $table->text('detail')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'heartbeat_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deployment_health_checks');
    }
};
