<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * customer_success_health_scores — a point-in-time snapshot row per
 * computation (mirrors Phase 6's usage_rollups pattern), not a single
 * mutable row per firm. All count/usage columns are safe aggregate
 * numbers only — no document content, no matter content, no message
 * bodies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_success_health_scores', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->timestamp('computed_at')->useCurrent();

            $table->unsignedTinyInteger('score');
            $table->string('risk_level');
            $table->unsignedTinyInteger('onboarding_progress_percent')->nullable();
            $table->timestamp('last_login_at')->nullable();

            $table->unsignedInteger('active_users_count')->nullable();
            $table->unsignedInteger('matters_count')->nullable();
            $table->unsignedInteger('clients_count')->nullable();
            $table->unsignedInteger('documents_count')->nullable();
            $table->unsignedInteger('invoices_count')->nullable();
            $table->unsignedInteger('payment_plans_count')->nullable();
            $table->unsignedInteger('payments_count')->nullable();
            $table->unsignedInteger('ai_usage_count')->nullable();
            $table->unsignedBigInteger('storage_bytes')->nullable();
            $table->unsignedInteger('failed_jobs_count')->nullable();
            $table->unsignedInteger('open_tickets_count')->nullable();
            $table->string('subscription_status')->nullable();

            $table->json('risk_flags')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'computed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_success_health_scores');
    }
};
