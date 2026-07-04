<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * product_analytics_events — append-only, no uuid (high-volume internal
 * event stream, mirrors security_events/platform_billing_events). No
 * updated_at. event_type is constrained at the application layer to
 * ProductAnalyticsEventType — this migration does not add a DB-level
 * check constraint, matching how event_type is stored as a plain string
 * on every other event-log table in this project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_analytics_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->nullable()->constrained('firms')->nullOnDelete();

            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->string('event_type');
            $table->timestamp('occurred_at')->useCurrent();
            $table->json('metadata')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index('firm_id');
            $table->index('event_type');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_analytics_events');
    }
};
