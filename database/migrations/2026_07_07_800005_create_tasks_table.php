<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tasks — representative fields match the master plan PDF's appendix
 * row ("id; firm_id; matter_id; assigned_to; title; status; priority;
 * due_at; completed_at; created_by") plus client_id, which this
 * phase's Scope rule explicitly adds ("tasks linked to firm, matter,
 * client where applicable") beyond the literal appendix row.
 * TaskDependencyService is the only place status becomes Blocked;
 * TaskService derives Overdue from due_at rather than accepting it as
 * a directly-settable value (PDF: "overdue is derived... not manually
 * trusted").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('open');
            $table->string('priority')->default('normal');

            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'matter_id']);
            $table->index('assigned_to');
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
