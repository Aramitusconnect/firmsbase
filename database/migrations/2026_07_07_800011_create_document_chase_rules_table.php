<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_chase_rules — a firm may define MULTIPLE named rules (e.g.
 * different cadence for immigration filings vs. general document
 * requests), per approved decision — firm_id is not unique.
 * applies_to is a simple plain-string scope key (e.g. a practice_area
 * key, or 'all') rather than a complex rule-matching sub-table, to
 * stay inside the approved 15-table data contract.
 * reminder_offsets_days/max_reminders/escalate_after_days are the
 * schedule/escalation configuration; DocumentChaseSchedulerService
 * reads them, DocumentChaseService applies them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chase_rules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('name');
            $table->string('status')->default('active');
            $table->string('applies_to')->nullable();

            $table->json('reminder_offsets_days');
            $table->unsignedInteger('max_reminders')->default(3);
            $table->unsignedInteger('escalate_after_days')->nullable();
            $table->foreignId('escalate_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel')->default('email');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chase_rules');
    }
};
