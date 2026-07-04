<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sales_rep_assignments — polymorphic over PlatformLead|Opportunity via
 * assignable_type/assignable_id. No FK constraint on the polymorphic
 * pair (standard Laravel polymorphic pattern), only indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_rep_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('assignable_type');
            $table->unsignedBigInteger('assignable_id');

            $table->foreignId('platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();

            $table->string('status')->default('active');
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('reassigned_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['assignable_type', 'assignable_id']);
            $table->index('platform_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_rep_assignments');
    }
};
