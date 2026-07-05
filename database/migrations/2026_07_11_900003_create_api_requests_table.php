<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * api_requests — append-only API request audit log, no uuid (mirrors
 * security_events/platform_billing_events). firm_id is nullable and
 * denormalized from the acting api_key (null for platform-scoped
 * keys). endpoint_identifier is a logical name (e.g. "clients.index"),
 * not a real route — no routes exist in this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();

            $table->string('endpoint_identifier');
            $table->string('method', 10)->nullable();
            $table->string('status')->default('success');
            $table->string('scope_used')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->timestamp('created_at')->useCurrent();

            $table->index('api_key_id');
            $table->index('firm_id');
            $table->index('status');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
    }
};
