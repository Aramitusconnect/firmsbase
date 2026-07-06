<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * data_processing_records — informational processing-activity register
 * linking purpose, data category, vendor/subprocessor, and retention
 * rule (approved decision #7). No external call, no compliance claim
 * beyond recorded metadata — this table asserts nothing on its own; it
 * is a declared record for admin/legal review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_processing_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('record_type');
            $table->text('purpose');
            $table->json('data_categories_json')->nullable();
            $table->text('legal_basis')->nullable();

            $table->foreignId('vendor_register_id')->nullable()->constrained('vendor_register')->nullOnDelete();
            $table->foreignId('subprocessor_id')->nullable()->constrained('subprocessors')->nullOnDelete();
            $table->foreignId('retention_policy_id')->nullable()->constrained('retention_policies')->nullOnDelete();
            $table->foreignId('firm_id')->nullable()->constrained('firms')->cascadeOnDelete();

            $table->string('status')->default('active');
            $table->foreignId('recorded_by_platform_admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamp('last_reviewed_at')->nullable();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_processing_records');
    }
};
