<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notification_events — append-only: each row is a single point-in-
 * time event (attempted, then later a separate sent row, then possibly
 * a much-later separate bounced row when a bounce webhook arrives) —
 * NOT one mutable row updated in place. correlation_id ties the
 * attempted/queued/sent/bounced rows of one logical notification
 * together. Must record when a notification was blocked because the
 * sender/domain was unverified (project rule) — reason captures this
 * explicitly, e.g. "sender domain not verified: mail.example.com".
 * NotificationDispatchService is the only place these rows are
 * created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('notification_template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();

            $table->uuid('correlation_id');
            $table->string('channel');
            $table->string('recipient');
            $table->string('status')->default('attempted');
            $table->text('reason')->nullable();

            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'client_id']);
            $table->index('correlation_id');
            $table->index('status');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};
