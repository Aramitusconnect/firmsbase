<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * health_checks — a new row is written every time a check runs
 * (append-only; the log itself is the history, same convention as
 * document_chase_events/readiness_score_events). firm_id is nullable:
 * null for platform-infrastructure checks (web uptime, queue workers,
 * scheduler, failed jobs, storage, email delivery, payment webhooks,
 * document scanning), non-null when a check result is specific to one
 * firm (tenant isolation anomalies). check_type is a closed enum
 * (HealthCheckType) because monitoring surfaces are a reviewed,
 * deliberate list, not an arbitrary per-firm catalog — unlike Phase
 * 4's ReadinessScorecardComponent, no schema-free registry table is
 * used here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_checks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();

            $table->string('check_type');
            $table->string('status');
            $table->text('detail')->nullable();
            $table->timestamp('checked_at');
            $table->json('metadata_json')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['check_type', 'checked_at']);
            $table->index('firm_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_checks');
    }
};
