<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * calendar_events — "Calendar events can represent deadline/reminder/
 * matter activity" (Scope). subject_type/subject_id form a lightweight
 * polymorphic reference (same pattern as timeline_events.subject) to
 * whatever this entry represents when auto-created by DeadlineService/
 * TaskService; a standalone staff-created event has both null. No
 * external Google/Outlook sync (out of phase, project rule) — this is
 * purely FirmsBase's own internal calendar record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();

            $table->string('event_type')->default('standalone');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('all_day')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
