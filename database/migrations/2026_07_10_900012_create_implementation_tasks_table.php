<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * implementation_tasks — mirrors activation_checklist_items exactly:
 * no firm_id of its own (scoped transitively through
 * implementation_project_id), unique (implementation_project_id,
 * task_key). Standard task_key set: kickoff, import_planning,
 * template_selection, user_setup, client_portal_setup,
 * email_verification, consent_capture_setup, payment_mode_confirmation,
 * staff_training, go_live_review, success_review_30_day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('implementation_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('implementation_project_id')->constrained('implementation_projects')->cascadeOnDelete();

            $table->string('task_key');
            $table->string('status')->default('pending');
            $table->boolean('is_required')->default(true);

            $table->foreignId('completed_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['implementation_project_id', 'task_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('implementation_tasks');
    }
};
