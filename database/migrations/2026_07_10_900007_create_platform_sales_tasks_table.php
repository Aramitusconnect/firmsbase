<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_sales_tasks — platform sales follow-up tasks only. Deliberately
 * named platform_sales_tasks, NOT tasks, to avoid mixing platform sales
 * operations with Phase 4's legal-workflow tasks table (firm/matter/
 * client scoped). Polymorphic over PlatformLead|Opportunity via
 * taskable_type/taskable_id, matching sales_rep_assignments' pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_sales_tasks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('taskable_type');
            $table->unsignedBigInteger('taskable_id');

            $table->foreignId('assigned_to')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->string('title');
            $table->string('status')->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['taskable_type', 'taskable_id']);
            $table->index('status');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sales_tasks');
    }
};
