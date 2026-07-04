<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * time_tracking_sessions — a running/paused/stopped timer. Elapsed
 * time is accumulated as a whole-second integer (accumulated_seconds)
 * rather than derived from timestamp subtraction at read time, so a
 * pause/resume cycle can never introduce a fractional or negative
 * value (project rule: "Prevent fractional idle/time values"). No
 * uuid — internal timer mechanics only, never addressed individually
 * via a public surface. When stopped, TimeTrackingService creates
 * exactly one TimeEntry from total_seconds; the link is on
 * time_entries.time_tracking_session_id (this table has no reverse FK,
 * avoiding a circular creation-order dependency between the two
 * tables).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_tracking_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->string('status')->default('active');

            $table->timestamp('started_at');
            $table->unsignedInteger('accumulated_seconds')->default(0);
            $table->timestamp('last_resumed_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('total_seconds')->nullable();

            $table->boolean('is_billable')->default(true);
            $table->text('description')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'user_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_tracking_sessions');
    }
};
