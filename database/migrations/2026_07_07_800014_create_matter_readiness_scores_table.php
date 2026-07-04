<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_readiness_scores — one current row per matter (unique
 * matter_id), recomputed in place by MatterReadinessService.
 * breakdown_json snapshots each ACTIVE registered component's
 * satisfied/detail result from the last computation — components that
 * are Inactive or not yet registered are simply absent from
 * breakdown_json, which is exactly what makes "readiness must work
 * only with components that currently exist" true without any special
 * casing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_readiness_scores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->unique()->constrained('matters')->cascadeOnDelete();

            $table->string('status')->default('not_ready');
            $table->unsignedInteger('satisfied_count')->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->json('breakdown_json')->nullable();
            $table->timestamp('computed_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_readiness_scores');
    }
};
